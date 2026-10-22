<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\Placement;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;
use Throwable;

/**
 * Pengajuan cuti (PRD-001 §13.3, HR-3b): hitung hari, ajukan, tarik kembali.
 * Kelola cuti Admin SDM (§13.5, HR-3d): catat atas nama pegawai (langsung disetujui), batalkan.
 *
 * Aturan [OWNER + ASUMSI disetujui, 2026-10-22]:
 * - Pengaju = pegawai AKTIF dengan penempatan utama terbuka; lembaganya menentukan kalender kerja
 *   dan penyetuju tahap 1. Satuan = hari kerja penuh (WorkCalendar).
 * - Tanggal boleh di masa depan; boleh mundur paling lama 30 hari; rentang paling lama 366 hari.
 * - Ditolak bila: 0 hari kerja, melebihi batas hari jenis cuti, bertumpang tanggal dengan pengajuan
 *   lain yang menunggu/disetujui, atau (jenis berjatah) melebihi sisa jatah tahun itu dikurangi
 *   pengajuan lain yang masih menunggu.
 * - Penarikan hanya selama masih menunggu.
 * - Catat atas nama: aturan hari, saldo & tumpang tindih sama; boleh mundur paling lama 90 hari
 *   [ASUMSI]. Pembatalan hanya untuk cuti yang sudah disetujui, alasan wajib; saldo kembali.
 * - Admin tidak mencatat atau membatalkan cutinya sendiri (BR-017).
 *
 * Hak akses (pegawai itu sendiri / Admin SDM) dicek pemanggil. Alasan tidak masuk audit.
 *
 * @phpstan-type Plan array{organization_id: string, organization: string, days: int, days_by_year: array<int, int>, start: string, end: string}
 */
final class ManageLeaveRequests
{
    public const REASON_MAX_LENGTH = 500;

    public const BACKDATE_DAYS = 30;

    public const MAX_SPAN_DAYS = 366;

    /** Catat atas nama boleh lebih mundur dari pengajuan sendiri (mis. sakit yang dilaporkan belakangan). */
    public const RECORD_BACKDATE_DAYS = 90;

    public const CANCEL_REASON_MAX_LENGTH = LeaveApprovals::NOTE_MAX_LENGTH;

    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly LeaveBalances $balances,
        private readonly LeaveApprovalSetting $approval,
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Hitung hari kerja pengajuan (untuk pratinjau dan simpan).
     *
     * @return Plan
     *
     * @throws LeaveException
     */
    public function plan(string $tenantId, string $employeeId, string $startDate, string $endDate, int $backdateDays = self::BACKDATE_DAYS): array
    {
        $employee = Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->first();

        if (! $employee instanceof Employee || $employee->status !== EmployeeStatus::Active) {
            throw LeaveException::make('form', 'Hanya pegawai aktif yang bisa mengajukan cuti.');
        }

        $placement = Placement::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->first();

        if (! $placement instanceof Placement) {
            throw LeaveException::make('form', 'Pegawai ini belum punya penempatan utama. Hubungi Admin SDM.');
        }

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');

        if ($end->lessThan($start)) {
            throw LeaveException::make('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        if ($start->diffInDays($end) + 1 > self::MAX_SPAN_DAYS) {
            throw LeaveException::make('end_date', 'Satu pengajuan paling lama '.self::MAX_SPAN_DAYS.' hari.');
        }

        if ($start->lessThan(BusinessClock::today()->subDays($backdateDays))) {
            throw LeaveException::make('start_date', "Tanggal mulai paling lama {$backdateDays} hari sebelum hari ini.");
        }

        $dates = $this->calendar->workingDays($tenantId, $placement->organization_id, $start->toDateString(), $end->toDateString());

        if ($dates === []) {
            throw LeaveException::make('end_date', 'Tidak ada hari kerja pada rentang tanggal ini (semuanya libur).');
        }

        $byYear = [];

        foreach ($dates as $date) {
            $year = (int) substr($date, 0, 4);
            $byYear[$year] = ($byYear[$year] ?? 0) + 1;
        }

        return [
            'organization_id' => $placement->organization_id,
            'organization' => (string) DB::table('organizations')->where('id', $placement->organization_id)->value('name'),
            'days' => count($dates),
            'days_by_year' => $byYear,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    /**
     * Pengajuan oleh pegawai itu sendiri (Cuti saya) atau lewat jalur lain yang menunggu persetujuan.
     *
     * @param  bool  $selfService  diajukan pegawai itu sendiri (Cuti saya): bila ia sendiri berhak menyetujui
     *                             tahap lembaga (dicek dari lembaga kerja aktifnya), tahap 1 dilewati dan
     *                             pengajuan langsung ke tahap SDM (BR-017, PRD-001 §13.4)
     *
     * @throws LeaveException
     */
    public function submit(string $tenantId, string $employeeId, string $typeId, string $startDate, string $endDate, string $reason, bool $selfService = false): LeaveRequest
    {
        return $this->create($tenantId, $employeeId, $typeId, $startDate, $endDate, $reason, $selfService ? 'self' : 'submit', null);
    }

    /**
     * Admin SDM mencatat cuti atas nama pegawai: langsung disetujui (PRD-001 §13.5).
     *
     * @throws LeaveException
     */
    public function record(string $tenantId, string $employeeId, string $typeId, string $startDate, string $endDate, string $reason, string $recordedBy): LeaveRequest
    {
        $this->assertNotOwn($tenantId, $employeeId, $recordedBy, 'mencatat');

        return $this->create($tenantId, $employeeId, $typeId, $startDate, $endDate, $reason, 'record', $recordedBy);
    }

    /**
     * Admin SDM membatalkan cuti yang sudah disetujui; saldo kembali (PRD-001 §13.5).
     *
     * @throws LeaveException
     */
    public function cancel(string $tenantId, string $requestId, string $cancelledBy, string $reason): LeaveRequest
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::CANCEL_REASON_MAX_LENGTH) {
            throw LeaveException::make('reason', 'Tulis alasan pembatalan, maksimal '.self::CANCEL_REASON_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $requestId, $cancelledBy, $reason): LeaveRequest {
            $request = Str::isUuid($requestId)
                ? LeaveRequest::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($requestId)->lockForUpdate()->first()
                : null;

            if (! $request instanceof LeaveRequest) {
                throw LeaveException::make('form', 'Pengajuan ini tidak ditemukan.');
            }

            if ($request->status !== LeaveStatus::Approved) {
                throw LeaveException::make('form', 'Hanya cuti yang sudah disetujui yang bisa dibatalkan.');
            }

            $this->assertNotOwn($tenantId, $request->employee_id, $cancelledBy, 'membatalkan');

            $request->status = LeaveStatus::Cancelled;
            $request->save();

            $this->decision($tenantId, $request->id, $request->approval_steps, LeaveApprovals::CANCELLED, $cancelledBy, $reason);

            $this->audit->record(
                HrAuditAction::LEAVE_CANCELLED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $request->employee_id,
                ['mulai' => $request->start_date->toDateString(), 'selesai' => $request->end_date->toDateString(), 'hari' => $request->days],
                organizationId: $request->organization_id,
                tenantId: $tenantId,
            );

            return $request;
        });
    }

    /**
     * @param  'self'|'submit'|'record'  $mode
     *
     * @throws LeaveException
     */
    private function create(string $tenantId, string $employeeId, string $typeId, string $startDate, string $endDate, string $reason, string $mode, ?string $recordedBy): LeaveRequest
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw LeaveException::make('reason', 'Tulis alasan cuti, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $employeeId, $typeId, $startDate, $endDate, $reason, $mode, $recordedBy): LeaveRequest {
            // Antrean per pegawai: cek tumpang tindih & saldo tidak balapan dengan pengajuan lain.
            Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->lockForUpdate()->first();

            $recorded = $mode === 'record';

            $type = Str::isUuid($typeId)
                ? LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->first()
                : null;

            if (! $type instanceof LeaveType || ! $type->is_active) {
                throw LeaveException::make('leave_type_id', 'Pilih jenis cuti yang masih berlaku.');
            }

            $plan = $this->plan($tenantId, $employeeId, $startDate, $endDate, $recorded ? self::RECORD_BACKDATE_DAYS : self::BACKDATE_DAYS);

            if ($type->max_days !== null && $plan['days'] > $type->max_days) {
                throw LeaveException::make('end_date', "{$type->name} paling lama {$type->max_days} hari kerja per pengajuan (pengajuan ini {$plan['days']} hari).");
            }

            $this->assertNoOverlap($employeeId, $plan['start'], $plan['end']);

            if ($type->has_quota) {
                foreach ($plan['days_by_year'] as $year => $days) {
                    $balance = $this->balances->of($type, $employeeId, $year);
                    $available = $balance['remaining'] - $balance['pending'];

                    if ($days > $available) {
                        throw LeaveException::make('end_date', "Sisa jatah {$type->name} tahun {$year} tinggal ".max(0, $available)." hari (sudah dikurangi pengajuan yang masih menunggu); pengajuan ini {$days} hari.");
                    }
                }
            }

            // Tidak boleh menyetujui cuti sendiri: pengaju yang juga penyetuju lembaganya langsung ke SDM.
            $skipLembaga = $mode === 'self' && $this->authorization->allows(HrAccess::LEAVE_APPROVE, $plan['organization_id']);
            $steps = $recorded ? 1 : ($skipLembaga ? 2 : $this->approval->stepsFor($tenantId, $plan['organization_id']));

            $request = new LeaveRequest;
            $request->fill([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_type_id' => $type->id,
                'organization_id' => $plan['organization_id'],
                'start_date' => $plan['start'],
                'end_date' => $plan['end'],
                'days' => $plan['days'],
                'days_by_year' => $plan['days_by_year'],
                'reason' => $reason,
                'status' => $recorded ? LeaveStatus::Approved : LeaveStatus::Pending,
                'approval_steps' => $steps,
                'current_step' => $recorded ? null : ($skipLembaga ? 2 : 1),
                'recorded_by_membership_id' => $recordedBy,
                'decided_at' => $recorded ? CarbonImmutable::now() : null,
            ]);
            $request->save();

            if ($skipLembaga) {
                $this->decision($tenantId, $request->id, 1, LeaveApprovals::SKIPPED, null, null);
            }

            if ($recorded) {
                $this->decision($tenantId, $request->id, 1, LeaveApprovals::RECORDED, $recordedBy, null);
            }

            $details = ['jenis' => $type->name, 'mulai' => $plan['start'], 'selesai' => $plan['end'], 'hari' => $plan['days'], 'lembaga' => $plan['organization']];

            $this->audit->record(
                $recorded ? HrAuditAction::LEAVE_RECORDED : HrAuditAction::LEAVE_REQUESTED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                $recorded ? $details : $details + ['tahap' => $request->approval_steps, 'tahap_lembaga_dilewati' => $skipLembaga],
                organizationId: $plan['organization_id'],
                tenantId: $tenantId,
            );

            return $request;
        });
    }

    /**
     * Pengaju menarik pengajuannya yang masih menunggu.
     *
     * @throws LeaveException
     */
    public function withdraw(string $tenantId, string $employeeId, string $requestId): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $requestId): LeaveRequest {
            $request = Str::isUuid($requestId)
                ? LeaveRequest::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('employee_id', $employeeId)->whereKey($requestId)->lockForUpdate()->first()
                : null;

            if (! $request instanceof LeaveRequest) {
                throw LeaveException::make('form', 'Pengajuan ini tidak ditemukan.');
            }

            if ($request->status !== LeaveStatus::Pending) {
                throw LeaveException::make('form', 'Pengajuan ini sudah diputuskan, jadi tidak bisa ditarik.');
            }

            $request->status = LeaveStatus::Withdrawn;
            $request->current_step = null;
            $request->decided_at = CarbonImmutable::now();
            $request->save();

            $this->audit->record(
                HrAuditAction::LEAVE_WITHDRAWN,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                ['mulai' => $request->start_date->toDateString(), 'selesai' => $request->end_date->toDateString(), 'hari' => $request->days],
                organizationId: $request->organization_id,
                tenantId: $tenantId,
            );

            return $request;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @throws LeaveException
     */
    private function assertNotOwn(string $tenantId, string $employeeId, string $membershipId, string $verb): void
    {
        $owner = DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->value('membership_id');

        if ($owner === $membershipId) {
            throw LeaveException::make('form', "Anda tidak bisa {$verb} cuti Anda sendiri. Ajukan lewat Cuti saya, atau minta Admin SDM lain.");
        }
    }

    private function decision(string $tenantId, string $requestId, int $step, string $decision, ?string $membershipId, ?string $note): void
    {
        DB::table('hr_leave_decisions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'leave_request_id' => $requestId,
            'step' => $step,
            'decision' => $decision,
            'decided_by_membership_id' => $membershipId,
            'note' => $note,
            'decided_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Tidak boleh bertumpang tanggal dengan pengajuan lain yang menunggu atau sudah disetujui.
     *
     * @throws LeaveException
     */
    private function assertNoOverlap(string $employeeId, string $start, string $end, ?string $exceptId = null): void
    {
        $clash = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('start_date')
            ->first();

        if ($clash instanceof LeaveRequest) {
            throw LeaveException::make('start_date', "Tanggalnya bertumpang dengan pengajuan lain ({$clash->start_date->toDateString()} s.d. {$clash->end_date->toDateString()}, {$clash->status->label()}).");
        }
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== trim($value)) {
            throw LeaveException::make($field, 'Tanggal tidak valid.');
        }

        return $date;
    }
}
