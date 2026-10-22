<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveRequest;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Persetujuan cuti berjenjang (PRD-001 §13.4, HR-3c).
 *
 * - Tahap 1 (lembaga): pemilik `hr.leave.approve` yang cakupannya (lembaga kerja aktif) memuat lembaga
 *   pengajuan. Tahap 2 (SDM): pemilik `hr.leave.manage` di tingkat Yayasan.
 * - Hak diperiksa saat memutuskan. Tidak boleh memutuskan cuti sendiri (BR-017).
 * - Setuju di tahap terakhir: tumpang tindih dengan cuti lain yang sudah disetujui & sisa jatah
 *   dicek ulang dalam transaksi yang mengunci pegawai.
 * - Tolak: catatan wajib (maks. 300), boleh dibaca pengaju; isinya tidak masuk audit.
 *
 * @phpstan-type InboxRow array{id: string, employee: string, type: string, organization: string, start_date: string, end_date: string, days: int, reason: string, step: int, steps: int, quota: array{remaining: int, pending_other: int}|null, created_at: string|null, lembaga_skipped: bool}
 */
final class LeaveApprovals
{
    public const APPROVED = 'SETUJU';

    public const REJECTED = 'TOLAK';

    public const SKIPPED = 'DILEWATI';

    /** Dicatat Admin SDM atas nama pegawai (HR-3d). */
    public const RECORDED = 'DICATAT';

    /** Cuti yang sudah disetujui dibatalkan Admin SDM (HR-3d). */
    public const CANCELLED = 'BATAL';

    public const NOTE_MAX_LENGTH = 300;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly LeaveBalances $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pengajuan yang menunggu keputusan pengguna ini (tanpa pengajuannya sendiri), terlama dulu.
     *
     * @return list<InboxRow>
     */
    public function inbox(string $tenantId, string $membershipId): array
    {
        $lembaga = $this->authorization->visibleNodeIds(HrAccess::LEAVE_APPROVE);
        $sdm = $this->canManage();

        if ($lembaga === [] && ! $sdm) {
            return [];
        }

        $rows = DB::table('hr_leave_requests as r')
            ->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', LeaveStatus::Pending->value)
            ->where('e.membership_id', '!=', $membershipId)
            ->where(function (Builder $query) use ($lembaga, $sdm): void {
                if ($lembaga !== []) {
                    $query->orWhere(fn (Builder $step) => $step->where('r.current_step', 1)->whereIn('r.organization_id', $lembaga));
                }

                if ($sdm) {
                    $query->orWhere('r.current_step', 2);
                }
            })
            ->orderBy('r.created_at')
            ->limit(200)
            ->get(['r.id', 'r.employee_id', 'r.leave_type_id', 'r.start_date', 'r.end_date', 'r.days', 'r.days_by_year', 'r.reason', 'r.current_step', 'r.approval_steps', 'r.created_at', 'p.name as employee', 't.name as type', 't.has_quota', 'o.name as organization']);

        $skipped = DB::table('hr_leave_decisions')
            ->whereIn('leave_request_id', $rows->pluck('id')->all())
            ->where('decision', self::SKIPPED)
            ->pluck('leave_request_id')
            ->flip()
            ->all();

        $types = LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get()->keyBy('id');
        $inbox = [];

        foreach ($rows as $row) {
            $quota = null;
            $type = $types->get((string) $row->leave_type_id);

            if ($type instanceof LeaveType && $type->has_quota) {
                $year = (int) substr((string) $row->start_date, 0, 4);
                $balance = $this->balances->of($type, (string) $row->employee_id, $year, (string) $row->id);
                $quota = ['remaining' => $balance['remaining'], 'pending_other' => $balance['pending']];
            }

            $inbox[] = [
                'id' => (string) $row->id,
                'employee' => (string) $row->employee,
                'type' => (string) $row->type,
                'organization' => (string) $row->organization,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'days' => (int) $row->days,
                'reason' => (string) $row->reason,
                'step' => (int) $row->current_step,
                'steps' => (int) $row->approval_steps,
                'quota' => $quota,
                'created_at' => $row->created_at === null ? null : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'lembaga_skipped' => isset($skipped[(string) $row->id]),
            ];
        }

        return $inbox;
    }

    /**
     * Pengguna ini berhak memutuskan tahap yang sedang menunggu pada pengajuan ini.
     */
    public function canDecide(LeaveRequest $request): bool
    {
        if ($request->status !== LeaveStatus::Pending) {
            return false;
        }

        return $request->current_step === 1
            ? $this->authorization->allows(HrAccess::LEAVE_APPROVE, $request->organization_id)
            : $this->canManage();
    }

    /**
     * @return LeaveStatus status sesudah keputusan (masih Menunggu bila naik ke tahap berikutnya)
     *
     * @throws LeaveException
     */
    public function decide(string $tenantId, string $requestId, string $membershipId, bool $approve, ?string $note): LeaveStatus
    {
        $note = trim((string) $note);

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw LeaveException::make('note', 'Catatan maksimal '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        if (! $approve && $note === '') {
            throw LeaveException::make('note', 'Tulis alasan penolakan.');
        }

        return DB::transaction(function () use ($tenantId, $requestId, $membershipId, $approve, $note): LeaveStatus {
            $employeeId = Str::isUuid($requestId)
                ? DB::table('hr_leave_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->value('employee_id')
                : null;

            if (! is_string($employeeId)) {
                throw LeaveException::make('form', 'Pengajuan ini tidak ditemukan.');
            }

            // Antrean per pegawai (sama dengan pengajuan): saldo & tumpang tindih tidak balapan.
            $employeeMembership = DB::table('hr_employees')->where('id', $employeeId)->lockForUpdate()->value('membership_id');
            $request = LeaveRequest::query()->withoutGlobalScope('tenant')->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveStatus::Pending || $request->current_step === null) {
                throw LeaveException::make('form', 'Pengajuan ini sudah diputuskan. Muat ulang halaman.');
            }

            if ($employeeMembership === $membershipId) {
                throw LeaveException::make('form', 'Anda tidak bisa memutuskan cuti Anda sendiri.');
            }

            if (! $this->canDecide($request)) {
                throw LeaveException::make('form', 'Anda tidak berhak memutuskan tahap ini.');
            }

            $step = $request->current_step;
            $final = ! $approve || $step >= $request->approval_steps;

            if ($approve && $final) {
                $this->assertStillFits($request);
            }

            DB::table('hr_leave_decisions')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'leave_request_id' => $request->id,
                'step' => $step,
                'decision' => $approve ? self::APPROVED : self::REJECTED,
                'decided_by_membership_id' => $membershipId,
                'note' => $note === '' ? null : $note,
                'decided_at' => CarbonImmutable::now(),
            ]);

            if ($final) {
                $request->status = $approve ? LeaveStatus::Approved : LeaveStatus::Rejected;
                $request->current_step = null;
                $request->decided_at = CarbonImmutable::now();
            } else {
                $request->current_step = $step + 1;
            }

            $request->save();

            $this->audit->record(
                $approve ? HrAuditAction::LEAVE_APPROVED : HrAuditAction::LEAVE_REJECTED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $request->employee_id,
                [
                    'mulai' => $request->start_date->toDateString(),
                    'selesai' => $request->end_date->toDateString(),
                    'hari' => $request->days,
                    'tahap' => $step === 1 ? 'lembaga' : 'SDM',
                    'final' => $final,
                    'catatan_diisi' => $note !== '',
                ],
                organizationId: $request->organization_id,
                tenantId: $tenantId,
            );

            return $request->status;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        return $this->authorization->inspectTarget(HrAccess::LEAVE_MANAGE, null, null)->allowed();
    }

    /**
     * Cek ulang saat persetujuan terakhir: tidak bertumpang dengan cuti lain yang sudah disetujui dan
     * (jenis berjatah) sisa jatah tiap tahun masih cukup.
     *
     * @throws LeaveException
     */
    private function assertStillFits(LeaveRequest $request): void
    {
        $clash = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $request->employee_id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereKeyNot($request->id)
            ->where('start_date', '<=', $request->end_date->toDateString())
            ->where('end_date', '>=', $request->start_date->toDateString())
            ->first();

        if ($clash instanceof LeaveRequest) {
            throw LeaveException::make('form', "Tanggalnya bertumpang dengan cuti lain yang sudah disetujui ({$clash->start_date->toDateString()} s.d. {$clash->end_date->toDateString()}). Tolak pengajuan ini.");
        }

        $type = LeaveType::query()->withoutGlobalScope('tenant')->whereKey($request->leave_type_id)->firstOrFail();

        if (! $type->has_quota) {
            return;
        }

        foreach ($request->days_by_year as $year => $days) {
            $balance = $this->balances->of($type, $request->employee_id, (int) $year, $request->id);

            if ($days > $balance['remaining']) {
                throw LeaveException::make('form', "Sisa jatah {$type->name} tahun {$year} tinggal {$balance['remaining']} hari, kurang dari {$days} hari yang diajukan. Tolak pengajuan ini atau minta Admin SDM menambah jatah.");
            }
        }
    }
}
