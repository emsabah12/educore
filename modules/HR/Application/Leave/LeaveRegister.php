<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Domain\Leave\LeaveStatus;
use stdClass;

/**
 * Daftar & detail semua pengajuan cuti di yayasan untuk Admin SDM (PRD-001 §13.5, HR-3d).
 * Hak akses (`hr.leave.manage` di tingkat Yayasan) dicek pemanggil.
 *
 * Saringan: lembaga/unit (termasuk lembaga di bawahnya), jenis, status, bulan (cuti yang jatuh di
 * bulan itu). Urutan: tanggal mulai terbaru dulu. 50 baris per halaman.
 *
 * @phpstan-type Filters array{lembaga: string, jenis: string, status: string, bulan: string}
 * @phpstan-type Row array{id: string, employee_id: string, employee: string, type: string, organization: string, start_date: string, end_date: string, days: int, status: string, status_label: string, recorded: bool}
 * @phpstan-type DecisionRow array{decision: string, label: string, by: string|null, note: string|null, decided_at: string}
 */
final class LeaveRegister
{
    public const PER_PAGE = 50;

    /**
     * Saringan yang tidak valid diabaikan (dikosongkan), bukan error.
     *
     * @param  array<array-key, mixed>  $query
     * @return Filters
     */
    public static function filters(array $query): array
    {
        $text = function (string $key) use ($query): string {
            $value = $query[$key] ?? null;

            return is_string($value) ? trim($value) : '';
        };

        $lembaga = $text('lembaga');
        $jenis = $text('jenis');
        $status = $text('status');
        $bulan = $text('bulan');

        return [
            'lembaga' => Str::isUuid($lembaga) ? $lembaga : '',
            'jenis' => Str::isUuid($jenis) ? $jenis : '',
            'status' => LeaveStatus::tryFrom($status) instanceof LeaveStatus ? $status : '',
            'bulan' => preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $bulan) === 1 ? $bulan : '',
        ];
    }

    /**
     * @param  Filters  $filters
     * @return array{rows: list<Row>, page: LengthAwarePaginator<int, stdClass>}
     */
    public function search(string $tenantId, array $filters): array
    {
        $query = DB::table('hr_leave_requests as r')
            ->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.tenant_id', $tenantId);

        if ($filters['lembaga'] !== '') {
            // Lembaga/unit terpilih beserta semua lembaga di bawahnya.
            $query->whereIn('r.organization_id', fn (Builder $sub) => $sub
                ->select('descendant_id')
                ->from('organization_closure')
                ->where('tenant_id', $tenantId)
                ->where('ancestor_id', $filters['lembaga']));
        }

        if ($filters['jenis'] !== '') {
            $query->where('r.leave_type_id', $filters['jenis']);
        }

        if ($filters['status'] !== '') {
            $query->where('r.status', $filters['status']);
        }

        if ($filters['bulan'] !== '') {
            $first = CarbonImmutable::createFromFormat('!Y-m', $filters['bulan']);

            if ($first instanceof CarbonImmutable) {
                $query->where('r.start_date', '<=', $first->endOfMonth()->toDateString())
                    ->where('r.end_date', '>=', $first->toDateString());
            }
        }

        /** @var LengthAwarePaginator<int, stdClass> $page */
        $page = $query
            ->orderByDesc('r.start_date')
            ->orderBy('p.name')
            ->orderBy('r.id')
            ->select(['r.id', 'r.employee_id', 'r.start_date', 'r.end_date', 'r.days', 'r.status', 'r.current_step', 'r.approval_steps', 'r.recorded_by_membership_id', 'p.name as employee', 't.name as type', 'o.name as organization'])
            ->paginate(self::PER_PAGE);

        $rows = [];

        foreach ($page->items() as $row) {
            $status = LeaveStatus::from((string) $row->status);

            $rows[] = [
                'id' => (string) $row->id,
                'employee_id' => (string) $row->employee_id,
                'employee' => (string) $row->employee,
                'type' => (string) $row->type,
                'organization' => (string) $row->organization,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'days' => (int) $row->days,
                'status' => $status->value,
                'status_label' => self::statusLabel($status, $row->current_step === null ? null : (int) $row->current_step, (int) $row->approval_steps),
                'recorded' => $row->recorded_by_membership_id !== null,
            ];
        }

        return ['rows' => $rows, 'page' => $page->withQueryString()];
    }

    /**
     * @return array{id: string, employee_id: string, employee: string, employee_membership_id: string, type: string, organization: string, start_date: string, end_date: string, days: int, days_by_year: array<string, int>, reason: string, status: string, status_label: string, recorded_by: string|null, created_at: string|null, decisions: list<DecisionRow>}|null
     */
    public function detail(string $tenantId, string $requestId): ?array
    {
        if (! Str::isUuid($requestId)) {
            return null;
        }

        $row = DB::table('hr_leave_requests as r')
            ->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->leftJoin('memberships as rm', 'rm.id', '=', 'r.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'rm.person_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.id', $requestId)
            ->first(['r.id', 'r.employee_id', 'e.membership_id', 'r.start_date', 'r.end_date', 'r.days', 'r.days_by_year', 'r.reason', 'r.status', 'r.current_step', 'r.approval_steps', 'r.created_at', 'p.name as employee', 't.name as type', 'o.name as organization', 'rp.name as recorded_by']);

        if (! $row instanceof stdClass) {
            return null;
        }

        $status = LeaveStatus::from((string) $row->status);
        $byYear = [];

        foreach ((array) json_decode((string) $row->days_by_year, true) as $year => $days) {
            $byYear[(string) $year] = (int) $days;
        }

        return [
            'id' => (string) $row->id,
            'employee_id' => (string) $row->employee_id,
            'employee' => (string) $row->employee,
            'employee_membership_id' => (string) $row->membership_id,
            'type' => (string) $row->type,
            'organization' => (string) $row->organization,
            'start_date' => substr((string) $row->start_date, 0, 10),
            'end_date' => substr((string) $row->end_date, 0, 10),
            'days' => (int) $row->days,
            'days_by_year' => $byYear,
            'reason' => (string) $row->reason,
            'status' => $status->value,
            'status_label' => self::statusLabel($status, $row->current_step === null ? null : (int) $row->current_step, (int) $row->approval_steps),
            'recorded_by' => $row->recorded_by === null ? null : (string) $row->recorded_by,
            'created_at' => $row->created_at === null ? null : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'decisions' => $this->decisions($requestId),
        ];
    }

    public static function statusLabel(LeaveStatus $status, ?int $step, int $steps): string
    {
        if ($status === LeaveStatus::Pending && $step !== null) {
            return $step === 1 ? "Menunggu tahap lembaga ({$step} dari {$steps})" : "Menunggu tahap SDM ({$step} dari {$steps})";
        }

        return $status->label();
    }

    /**
     * @return list<DecisionRow>
     */
    private function decisions(string $requestId): array
    {
        $rows = DB::table('hr_leave_decisions as d')
            ->leftJoin('memberships as m', 'm.id', '=', 'd.decided_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->where('d.leave_request_id', $requestId)
            ->orderBy('d.decided_at')
            ->orderByRaw("d.decision = 'BATAL'")
            ->orderBy('d.step')
            ->get(['d.step', 'd.decision', 'd.note', 'd.decided_at', 'p.name']);

        $result = [];

        foreach ($rows as $row) {
            $stage = (int) $row->step === 1 ? 'tahap lembaga' : 'tahap SDM';

            $result[] = [
                'decision' => (string) $row->decision,
                'label' => match ((string) $row->decision) {
                    LeaveApprovals::APPROVED => "Disetujui ({$stage})",
                    LeaveApprovals::REJECTED => "Ditolak ({$stage})",
                    LeaveApprovals::SKIPPED => 'Tahap lembaga dilewati (pengaju penyetuju lembaganya sendiri)',
                    LeaveApprovals::RECORDED => 'Dicatat Admin SDM atas nama pegawai',
                    LeaveApprovals::CANCELLED => 'Dibatalkan',
                    default => (string) $row->decision,
                },
                'by' => $row->name === null ? null : (string) $row->name,
                'note' => $row->note === null ? null : (string) $row->note,
                'decided_at' => CarbonImmutable::parse((string) $row->decided_at)->toIso8601String(),
            ];
        }

        return $result;
    }
}
