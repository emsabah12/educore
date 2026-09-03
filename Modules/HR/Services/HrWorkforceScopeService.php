<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\HR\Exceptions\HrResourceScopeException;

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

        if ($context === null) {
            return false;
        }

        // Tenant berbeda -> DENY (HR-013 §6 default).
        if ($context->tenantId !== $tenantId) {
            return false;
        }

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
            )
            ->where('employments.employee_id', $employeeId)
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

        return $query->exists();
    }

    /**
     * Varian yang langsung melempar exception kalau tidak visible —
     * memudahkan pemakaian di controller (Step 3) tanpa mengulang
     * pengecekan `if` di setiap tempat.
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
}
