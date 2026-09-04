<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\HrResourceScopeException;
use Modules\HR\Models\Employee;

/**
 * Implementasi HR-013 §6 — Target Employee Scope Rule.
 *
 *     Employee -> Membership -> OrganizationalAssignment
 *
 * Employee dianggap "visible" dalam workspace organisasi/unit saat ini
 * HANYA JIKA Membership-nya punya Employment Placement TERBUKA
 * (effective_to NULL) yang merujuk ke Core OrganizationalAssignment
 * ber-status ACTIVE, di Organization (dan — kalau workspace saat ini
 * di-scope ke unit — OrganizationUnit) yang sama persis.
 *
 * "An employee with no open placement is not visible to a scoped-only
 * actor because there is no safe organizational ownership proof."
 * (HR-002 §12.2)
 *
 * Default fail-closed (HR-013 §6): sibling unit, organisasi berbeda,
 * atau tenant berbeda -> selalu DENY.
 */
final readonly class HrWorkforceScopeService
{
    public function __construct(
        private OrganizationalContextInterface $organizationalContext,
    ) {}

    public function isEmployeeVisibleInCurrentContext(
        string $tenantId,
        string $employeeId,
    ): bool {
        $context = $this->organizationalContext->getCurrentContext();

        if ($context === null || $context->tenantId !== $tenantId) {
            return false;
        }

        return $this->openPlacementQueryForContext($tenantId, $context)
            ->where('employments.employee_id', $employeeId)
            ->exists();
    }

    /**
     * Varian yang langsung melempar exception kalau tidak visible —
     * memudahkan pemakaian di controller tanpa mengulang pengecekan
     * `if` di setiap tempat.
     */
    public function assertEmployeeVisibleInCurrentContext(
        string $tenantId,
        string $employeeId,
    ): void {
        if (! $this->isEmployeeVisibleInCurrentContext($tenantId, $employeeId)) {
            throw new HrResourceScopeException(
                sprintf(
                    'Employee [%s] is not visible in the current organizational workspace.',
                    $employeeId,
                ),
            );
        }
    }

    /**
     * HR-017 §2.2 — Collection query untuk Workspace Employee Listing.
     *
     * Mengembalikan Eloquent query builder Employee yang SUDAH difilter
     * di level SQL sesuai HR-013 §31 (Collection Query Rule) — TIDAK
     * PERNAH mengambil semua Employee tenant lalu memfilter di PHP.
     * Controller tinggal memanggil ->paginate() langsung di atas query
     * ini.
     *
     * Kalau tidak ada OrganizationalContext aktif (atau tenant tidak
     * cocok), method ini mengembalikan query yang DIJAMIN kosong
     * (`whereRaw('1 = 0')`) — bukan null/exception — supaya controller
     * tidak perlu percabangan khusus dan tetap bisa memanggil
     * ->paginate() secara normal (menghasilkan halaman kosong, sesuai
     * kasus tepi HR-017 §2.5).
     */
    public function visibleEmployeesQuery(string $tenantId): EloquentBuilder
    {
        $query = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('employees.tenant_id', $tenantId);

        $context = $this->organizationalContext->getCurrentContext();

        if ($context === null || $context->tenantId !== $tenantId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(
            function (QueryBuilder $subquery) use ($tenantId, $context): void {
                $subquery
                    ->from('employment_placements')
                    ->join(
                        'employments',
                        'employments.id',
                        '=',
                        'employment_placements.employment_id',
                    )
                    ->join(
                        'organizational_assignments',
                        'organizational_assignments.id',
                        '=',
                        'employment_placements.organizational_assignment_id',
                    )
                    ->whereColumn(
                        'employments.employee_id',
                        'employees.id',
                    );

                $this->applyOpenPlacementConstraints(
                    $subquery,
                    $tenantId,
                    $context,
                );
            },
        );
    }

    private function openPlacementQueryForContext(
        string $tenantId,
        OrganizationalContext $context,
    ): QueryBuilder {
        $query = DB::table('employment_placements')
            ->join(
                'employments',
                'employments.id',
                '=',
                'employment_placements.employment_id',
            )
            ->join(
                'organizational_assignments',
                'organizational_assignments.id',
                '=',
                'employment_placements.organizational_assignment_id',
            );

        return $this->applyOpenPlacementConstraints(
            $query,
            $tenantId,
            $context,
        );
    }

    /**
     * Kondisi bersama yang dipakai baik oleh pengecekan single-Employee
     * (`isEmployeeVisibleInCurrentContext`) maupun query koleksi
     * (`visibleEmployeesQuery`) — SATU sumber kebenaran untuk aturan
     * HR-013 §6, supaya keduanya tidak pernah "menyimpang" seiring
     * waktu.
     */
    private function applyOpenPlacementConstraints(
        QueryBuilder $query,
        string $tenantId,
        OrganizationalContext $context,
    ): QueryBuilder {
        $query
            ->where('employment_placements.tenant_id', $tenantId)
            // Hanya Placement yang SEDANG BERJALAN yang jadi bukti
            // kepemilikan organisasi yang aman (bukan riwayat lama).
            ->whereNull('employment_placements.effective_to')
            ->where('organizational_assignments.status', 'ACTIVE')
            ->where('organizational_assignments.organization_id', $context->organizationId);

        // Kalau workspace saat ini di-scope ke unit tertentu, wajib
        // exact OrganizationUnit match — sibling unit tetap DENY.
        // Kalau workspace org-level (unit NULL), cukup Organization yang
        // sama (unit manapun di dalamnya boleh), sesuai HR-002 §12.2.
        if ($context->organizationUnitId !== null) {
            $query->where(
                'organizational_assignments.organization_unit_id',
                $context->organizationUnitId,
            );
        }

        return $query;
    }
}
