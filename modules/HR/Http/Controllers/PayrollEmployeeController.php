<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Application\Payroll\VariablePay;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use stdClass;

/**
 * Menu Gaji pegawai (PRD-001 §16.1, HR-5a-2): daftar pegawai aktif beserta ringkasan gaji tetapnya,
 * dan halaman setup gaji per pegawai (terpisah dari detail pegawai, keputusan owner 2026-10-27).
 * Hanya `hr.payroll.manage` di tingkat Yayasan → selain itu 403; pegawai yayasan lain → 404.
 */
final class PayrollEmployeeController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, EmployeePay $pay): Response
    {
        $this->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;

        $search = trim($request->string('cari')->value());
        $organizationId = $request->string('lembaga')->value();
        $organizationId = Str::isUuid($organizationId) ? $organizationId : null;
        $onlyUnset = $request->boolean('belum');

        $query = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value);

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $where) => $where->where('p.name', 'ilike', $pattern)->orWhere('e.employee_number', 'ilike', $pattern));
        }

        // Lembaga: penempatan berjalan di node itu atau turunannya.
        if ($organizationId !== null) {
            $query->whereExists(fn (Builder $placements) => $placements->select(DB::raw(1))
                ->from('hr_placements as fp')
                ->whereColumn('fp.employee_id', 'e.id')
                ->whereNull('fp.end_date')
                ->whereIn('fp.organization_id', fn (Builder $closure) => $closure->select('descendant_id')->from('organization_closure')->where('ancestor_id', $organizationId)));
        }

        // "Belum diatur": tanpa nominal komponen per pegawai. (Tarif jabatan dicek setelah dihitung.)
        if ($onlyUnset) {
            $query->whereNotExists(fn (Builder $amounts) => $amounts->select(DB::raw(1))
                ->from('hr_employee_pay_components as a')
                ->whereColumn('a.employee_id', 'e.id'));
        }

        $page = $query->orderBy('p.name')->orderBy('e.id')
            ->select(['e.id', 'e.employee_number', 'p.name'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<stdClass> $items */
        $items = array_values($page->items());
        $ids = array_map(fn (stdClass $row): string => (string) $row->id, $items);
        $summaries = $pay->summaries($tenantId, $ids);

        $rows = [];

        foreach ($items as $row) {
            $summary = $summaries[(string) $row->id];
            $rows[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'placements' => $summary['placements'],
                'position_total' => array_sum(array_map(fn (array $line): int => $line['kind'] === 'PENDAPATAN' ? $line['amount'] : -$line['amount'], $summary['positions'])),
                'monthly_earning' => $summary['monthly_earning'],
                'monthly_deduction' => $summary['monthly_deduction'],
                'per_present_day' => $summary['per_present_day'],
                'configured' => $summary['configured'],
            ];
        }

        return Inertia::render('hr/payroll/employees', [
            'rows' => $rows,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'filters' => ['cari' => $search, 'lembaga' => $organizationId, 'belum' => $onlyUnset],
            'organizations' => Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->get()
                ->map(fn (Organization $organization): array => ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code})"])->values()->all(),
        ]);
    }

    public function show(Request $request, string $employee, EmployeePay $pay, VariablePay $variable): Response
    {
        $this->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;
        $target = $this->employee($tenantId, $employee);
        $employeeId = (string) $target->id;
        $month = AttendanceRecap::month($request->query('bulan'));
        [, $until] = AttendanceRecap::range($month);
        $summary = $pay->forEmployee($tenantId, $employeeId);
        $teaching = $variable->teaching($tenantId, [$employeeId], $month)[$employeeId];
        $attendance = $variable->attendance($tenantId, $employeeId, $month);
        $presentDayPay = $summary['per_present_day'] * ($attendance['present_days'] ?? 0);

        return Inertia::render('hr/payroll/employee', [
            'employee' => [
                'id' => (string) $target->id,
                'name' => (string) $target->name,
                'employee_number' => $target->employee_number === null ? null : (string) $target->employee_number,
                'status' => (string) $target->status,
            ],
            'pay' => $summary,
            // Perkiraan bulan (HR-5b): angka akhir & kunci bulan di rekap gaji (HR-5c).
            'preview' => [
                'month' => $month,
                'maxMonth' => BusinessClock::today()->format('Y-m'),
                'label' => AttendanceRecap::monthLabel($month),
                'until' => $until,
                'teaching' => $teaching,
                'attendance' => $attendance,
                'present_day_pay' => $presentDayPay,
                'earning' => $summary['monthly_earning'] + $teaching['total'] + max(0, $presentDayPay),
                'deduction' => $summary['monthly_deduction'] + ($attendance['deduction'] ?? 0) + max(0, -$presentDayPay),
            ],
        ]);
    }

    public function update(Request $request, string $employee, EmployeePay $pay): RedirectResponse
    {
        $this->authorize();
        $tenantId = $this->tenantContext->require()->tenantId;
        $target = $this->employee($tenantId, $employee);

        $request->validate([
            'amounts' => ['present', 'array', 'max:200'],
            'amounts.*' => ['nullable', 'string', 'max:30'],
        ]);

        $amounts = $request->input('amounts', []);
        $context = $this->tenantContext->require();

        try {
            $changed = $pay->save($tenantId, (string) $target->id, is_array($amounts) ? $amounts : [], $context->membershipId);
        } catch (PayrollException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? 'Tidak ada perubahan.' : 'Gaji pegawai disimpan ('.count($changed).' komponen diubah).']);

        return back();
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::PAYROLL_MANAGE, null, null)->authorize();
    }

    private function employee(string $tenantId, string $employeeId): stdClass
    {
        $row = Str::isUuid($employeeId)
            ? DB::table('hr_employees as e')
                ->join('memberships as m', 'm.id', '=', 'e.membership_id')
                ->join('persons as p', 'p.id', '=', 'm.person_id')
                ->where('e.tenant_id', $tenantId)
                ->where('e.id', $employeeId)
                ->first(['e.id', 'e.employee_number', 'e.status', 'p.name'])
            : null;

        if (! $row instanceof stdClass) {
            abort(404);
        }

        return $row;
    }
}
