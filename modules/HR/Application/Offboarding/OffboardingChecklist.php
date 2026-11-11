<?php

namespace Modules\HR\Application\Offboarding;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\TeachingDuties;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Leave\LeaveStatus;
use stdClass;

/**
 * Tampilan checklist offboarding (PRD-001 §18.2, HR-7b): per pegawai (tab Offboarding) dan daftar Pegawai keluar.
 * Hak akses dicek pemanggil.
 *
 * Status checklist dihitung: SELESAI bila tidak ada item BELUM. Item `AKUN-LOGIN` yang masih BELUM dianggap selesai
 * otomatis bila akun login pegawai sudah nonaktif atau memang tidak ada (`auto`).
 *
 * Pemeriksaan (info, tidak memblokir): akun login, tugas mengajar yang masih tercatat (Academic), cuti yang masih
 * menunggu atau disetujui melewati tanggal berhenti.
 *
 * @phpstan-type TaskRow array{id: string, code: string, name: string, status: string, auto: bool, note: string|null, done_by: string|null, done_on: string|null}
 * @phpstan-type ChecklistRow array{id: string, end_date: string, reason: string|null, tasks: list<TaskRow>, done: int, total: int, completed: bool}
 * @phpstan-type ListRow array{employee_id: string, employee_name: string, employee_number: string|null, organization: string|null, end_date: string, reason: string|null, done: int, total: int, completed: bool}
 */
final class OffboardingChecklist
{
    public const FILTER_OPEN = 'berjalan';

    public const FILTER_ALL = 'semua';

    public function __construct(
        private readonly TeachingDuties $duties,
    ) {}

    /**
     * Checklist pegawai ini (terbaru di atas) + pemeriksaan keadaan sekarang.
     *
     * @return array{
     *     checklists: list<ChecklistRow>,
     *     canStart: bool,
     *     checks: array{account: 'NONE'|'ACTIVE'|'INACTIVE', membership_id: string|null, duties: list<string>, open_leaves: int}
     * }
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $employee = DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->first(['id', 'status', 'membership_id']);
        $account = $employee === null ? 'NONE' : $this->accountState((string) $employee->membership_id);
        $offboardings = DB::table('hr_offboardings as o')
            ->join('hr_employment_periods as p', 'p.id', '=', 'o.employment_period_id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.employee_id', $employeeId)
            ->orderByDesc('o.end_date')
            ->get(['o.id', 'o.end_date', 'p.end_reason']);
        $tasks = $this->tasks(array_values(array_map(fn (stdClass $row): string => (string) $row->id, $offboardings->all())));
        $checklists = [];

        foreach ($offboardings as $offboarding) {
            $checklists[] = $this->checklist($offboarding, $tasks[(string) $offboarding->id] ?? [], $account);
        }

        $ended = $employee !== null && (string) $employee->status === EmployeeStatus::Ended->value;
        $lastPeriod = $ended
            ? DB::table('hr_employment_periods')->where('employee_id', $employeeId)->whereNotNull('end_date')->orderByDesc('end_date')->value('id')
            : null;
        $endDate = $checklists[0]['end_date'] ?? BusinessClock::todayString();

        return [
            'checklists' => $checklists,
            'canStart' => $lastPeriod !== null && DB::table('hr_offboardings')->where('employment_period_id', $lastPeriod)->doesntExist(),
            'checks' => [
                'account' => $account,
                'membership_id' => $employee === null ? null : (string) $employee->membership_id,
                'duties' => $ended ? $this->duties->forEmployee($tenantId, $employeeId, $endDate) : [],
                'open_leaves' => $ended ? $this->openLeaves($employeeId, $endDate) : 0,
            ],
        ];
    }

    /**
     * Daftar Pegawai keluar: checklist yang masih berjalan dulu, lalu tanggal berhenti terbaru.
     *
     * @return list<ListRow>
     */
    public function list(string $tenantId, string $filter): array
    {
        $rows = DB::table('hr_offboardings as o')
            ->join('hr_employment_periods as p', 'p.id', '=', 'o.employment_period_id')
            ->join('hr_employees as e', 'e.id', '=', 'o.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as ps', 'ps.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pl', fn ($join) => $join
                ->on('pl.employee_id', '=', 'e.id')
                ->where('pl.is_primary', true)
                ->whereColumn('pl.end_date', 'o.end_date'))
            ->leftJoin('organizations as org', 'org.id', '=', 'pl.organization_id')
            ->where('o.tenant_id', $tenantId)
            ->orderByDesc('o.end_date')
            ->orderBy('ps.name')
            ->get(['o.id', 'o.end_date', 'p.end_reason', 'e.id as employee_id', 'e.membership_id', 'e.employee_number', 'ps.name', 'org.name as organization']);

        $tasks = $this->tasks(array_values(array_map(fn (stdClass $row): string => (string) $row->id, $rows->all())));
        $accounts = $this->accountStates(array_values(array_unique(array_map(fn (stdClass $row): string => (string) $row->membership_id, $rows->all()))));
        $open = [];
        $completed = [];

        foreach ($rows as $row) {
            $checklist = $this->checklist($row, $tasks[(string) $row->id] ?? [], $accounts[(string) $row->membership_id] ?? 'NONE');
            $item = [
                'employee_id' => (string) $row->employee_id,
                'employee_name' => (string) $row->name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization' => $row->organization === null ? null : (string) $row->organization,
                'end_date' => $checklist['end_date'],
                'reason' => $checklist['reason'],
                'done' => $checklist['done'],
                'total' => $checklist['total'],
                'completed' => $checklist['completed'],
            ];

            if ($checklist['completed']) {
                $completed[] = $item;
            } else {
                $open[] = $item;
            }
        }

        return $filter === self::FILTER_ALL ? [...$open, ...$completed] : $open;
    }

    /**
     * @param  list<stdClass>  $tasks
     * @param  'NONE'|'ACTIVE'|'INACTIVE'  $account
     * @return ChecklistRow
     */
    private function checklist(stdClass $offboarding, array $tasks, string $account): array
    {
        $rows = [];
        $done = 0;

        foreach ($tasks as $task) {
            $status = (string) $task->status;
            $auto = $status === ManageOffboarding::STATUS_PENDING && (string) $task->code === ManageOffboarding::ACCOUNT_ITEM && $account !== 'ACTIVE';

            if ($auto) {
                $status = ManageOffboarding::STATUS_DONE;
            }

            if ($status !== ManageOffboarding::STATUS_PENDING) {
                $done++;
            }

            $rows[] = [
                'id' => (string) $task->id,
                'code' => (string) $task->code,
                'name' => (string) $task->name,
                'status' => $status,
                'auto' => $auto,
                'note' => $task->note === null ? null : (string) $task->note,
                'done_by' => $task->done_by === null ? null : (string) $task->done_by,
                'done_on' => $task->done_at === null ? null : CarbonImmutable::parse((string) $task->done_at)->setTimezone(BusinessClock::timezone())->toDateString(),
            ];
        }

        $reason = $offboarding->end_reason === null ? null : EndReason::tryFrom((string) $offboarding->end_reason)?->label();

        return [
            'id' => (string) $offboarding->id,
            'end_date' => substr((string) $offboarding->end_date, 0, 10),
            'reason' => $reason,
            'tasks' => $rows,
            'done' => $done,
            'total' => count($rows),
            'completed' => $done === count($rows),
        ];
    }

    /**
     * @param  list<string>  $offboardingIds
     * @return array<string, list<stdClass>>
     */
    private function tasks(array $offboardingIds): array
    {
        if ($offboardingIds === []) {
            return [];
        }

        $grouped = [];

        foreach (DB::table('hr_offboarding_tasks as t')
            ->leftJoin('memberships as dm', 'dm.id', '=', 't.done_by_membership_id')
            ->leftJoin('persons as dp', 'dp.id', '=', 'dm.person_id')
            ->whereIn('t.offboarding_id', $offboardingIds)
            ->orderBy('t.position')
            ->get(['t.id', 't.offboarding_id', 't.code', 't.name', 't.status', 't.note', 't.done_at', 'dp.name as done_by']) as $task) {
            $grouped[(string) $task->offboarding_id][] = $task;
        }

        return $grouped;
    }

    /**
     * @return 'NONE'|'ACTIVE'|'INACTIVE'
     */
    private function accountState(string $membershipId): string
    {
        return $this->accountStates([$membershipId])[$membershipId] ?? 'NONE';
    }

    /**
     * @param  list<string>  $membershipIds
     * @return array<string, 'ACTIVE'|'INACTIVE'> akun login per keanggotaan (yang tidak punya akun tidak tercantum)
     */
    private function accountStates(array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $states = [];

        foreach (DB::table('memberships as m')
            ->join('users as u', 'u.person_id', '=', 'm.person_id')
            ->whereIn('m.id', $membershipIds)
            ->get(['m.id', 'u.status']) as $row) {
            $states[(string) $row->id] = (string) $row->status === UserStatus::Active->value ? 'ACTIVE' : 'INACTIVE';
        }

        return $states;
    }

    private function openLeaves(string $employeeId, string $endDate): int
    {
        return DB::table('hr_leave_requests')
            ->where('employee_id', $employeeId)
            ->where(fn ($query) => $query
                ->where('status', LeaveStatus::Pending->value)
                ->orWhere(fn ($approved) => $approved->where('status', LeaveStatus::Approved->value)->where('end_date', '>', $endDate)))
            ->count();
    }
}
