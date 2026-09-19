<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentServiceInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use RuntimeException;

/**
 * Implementasi transaksi "Workspace Employee Creation" dari HR-017 §3.2
 * (REVISI 2026-09-04 — lihat §3.4 keputusan #1).
 *
 * Kelas ini SENGAJA tidak memperkenalkan primitif baru — dia murni
 * mengorkestrasi 4 service yang sudah ada dan sudah teruji:
 *
 *     1. EmployeeProvisioningService::provision()
 *        -> Person + Membership + Employee.
 *     2. OrganizationalAssignmentService::assignToOrganization()/assignToUnit()
 *        -> Core OrganizationalAssignment (idempotent).
 *     3. EmploymentLifecycleService::createPlanned() + ::activate()
 *        -> Employment berstatus ACTIVE. WAJIB diaktifkan di sini (bukan
 *           tetap PLANNED seperti keputusan awal) karena langkah 4
 *           (Placement) mensyaratkan Employment ACTIVE — precondition
 *           yang sudah dikunci sejak RM-HR-01 Step 7
 *           (EmploymentPlacementService). Employee tanpa Placement
 *           bertentangan langsung dengan tujuan utama Bagian B ini
 *           (HR-017 §3.1).
 *     4. EmploymentPlacementService::createPlacement()
 *        -> Placement TERBUKA menunjuk ke Assignment dari langkah 2.
 *
 * INV-HR-012 (HR-017 §3.5): seluruh langkah di atas WAJIB dalam SATU
 * `DB::transaction()` — kalau langkah manapun gagal, semua langkah
 * sebelumnya (termasuk Person/Membership yang sudah tersimpan) ikut
 * di-rollback. Tidak boleh ada Employee "yatim" tanpa Placement.
 */
final readonly class WorkspaceEmployeeProvisioningService
{
    /**
     * §Langkah 2 (self-service leave gap remediation) — nama role
     * DISENGAJAKAN diduplikasi sebagai konstanta lokal, bukan
     * mereferensikan `EmployeeSelfServiceRoleSeeder::EMPLOYEE_ROLE`
     * secara langsung. Pola ini konsisten dengan bagaimana
     * `TenantProvisioningService::ADMIN_ROLE_NAME` juga menduplikasi
     * nilai yang sama persis dengan
     * `AuthorizationCatalogSeeder::ADMIN_ROLE_NAME` — kelas Service
     * TIDAK bergantung pada namespace Database\Seeders.
     */
    private const EMPLOYEE_ROLE_NAME = 'employee';

    public function __construct(
        private TenantContextInterface $tenantContext,
        private EmployeeProvisioningService $employeeProvisioningService,
        private OrganizationalAssignmentServiceInterface $organizationalAssignmentService,
        private EmploymentLifecycleService $employmentLifecycleService,
        private EmploymentPlacementService $employmentPlacementService,
        private MembershipRoleRepositoryInterface $membershipRoleRepository,
    ) {}

    /**
     * §Perbaikan gap self-service leave — pola query identik dengan
     * `TenantProvisioningService::requireAdminRole()`:
     * `whereNull('tenant_id')` WAJIB supaya pencarian role sistem
     * kanonik ini tidak pernah secara tidak sengaja mengambil role
     * kustom tenant lain yang kebetulan bernama sama.
     *
     * Gagal TEGAS (bukan diam-diam dilewati) kalau role belum ada —
     * berarti `EmployeeSelfServiceRoleSeeder` belum pernah dijalankan
     * di database ini.
     */
    private function requireEmployeeRole(): Role
    {
        $employeeRole = Role::query()
            ->whereNull('tenant_id')
            ->where('name', self::EMPLOYEE_ROLE_NAME)
            ->first();

        if ($employeeRole === null) {
            throw new RuntimeException(
                'Canonical employee role is unavailable. Run EmployeeSelfServiceRoleSeeder.',
            );
        }

        return $employeeRole;
    }

    /**
     * @param array{
     *     nama: string,
     *     nip: string,
     *     jabatan: string,
     *     employment_type_id: string,
     * } $employeeData
     * @return array{
     *     employee_id: string,
     *     membership_id: string,
     *     person_id: string,
     *     employment_id: string,
     *     employment_status: string,
     *     organizational_assignment_id: string,
     *     employment_placement_id: string,
     * }
     */
    public function provisionWithinWorkspace(
        string $tenantId,
        array $employeeData,
        string $organizationId,
        ?string $organizationUnitId,
    ): array {
        // Jaring pengaman: OrganizationalAssignmentService (Core)
        // mengambil tenant dari TenantContextInterface AMBIENT, bukan
        // parameter eksplisit (beda konvensi dari service-service HR).
        // Kalau context aktif ternyata tidak cocok dengan $tenantId yang
        // diminta, lebih baik gagal tegas di sini daripada diam-diam
        // membuat OrganizationalAssignment di tenant yang salah.
        if ($this->tenantContext->getCurrentTenantId() !== $tenantId) {
            throw new LogicException(
                sprintf(
                    'TenantContext mismatch: expected [%s], active context is [%s]. WorkspaceEmployeeProvisioningService must run within a request where InjectTenantContext already matches the target tenant.',
                    $tenantId,
                    $this->tenantContext->getCurrentTenantId() ?? 'null',
                ),
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $employeeData,
            $organizationId,
            $organizationUnitId,
        ): array {
            // Langkah 3 (HR-017 §3.2): Person + Membership + Employee.
            $employee = $this->employeeProvisioningService->provision(
                tenantId: $tenantId,
                data: [
                    'nama' => $employeeData['nama'],
                    'nip' => $employeeData['nip'],
                    'jabatan' => $employeeData['jabatan'],
                ],
            );

            // §Perbaikan gap self-service leave — SETIAP Employee yang
            // keluar dari transaksi ini WAJIB langsung punya akses ke
            // kapabilitas self-service miliknya sendiri (mis. ajukan
            // Cuti sendiri), tanpa menunggu tindakan manual admin
            // terpisah. Ditempatkan di sini (bukan menunggu akun login
            // dibuat lewat EmployeeAccountProvisioningService) karena
            // kapabilitas ini secara konsep melekat pada STATUS
            // "menjadi Employee", bukan pada "punya kredensial login".
            $this->membershipRoleRepository->assignRole(
                $employee['membership_id'],
                $tenantId,
                (string) $this->requireEmployeeRole()->id,
            );

            // Langkah 4: Core OrganizationalAssignment. HR-017 §3.4
            // keputusan #2 — unit OPSIONAL: kalau workspace di-scope ke
            // unit, assignToUnit(); kalau org-level, assignToOrganization().
            $assignment = $organizationUnitId !== null
                ? $this->organizationalAssignmentService->assignToUnit(
                    membershipId: $employee['membership_id'],
                    organizationId: $organizationId,
                    organizationUnitId: $organizationUnitId,
                )
                : $this->organizationalAssignmentService->assignToOrganization(
                    membershipId: $employee['membership_id'],
                    organizationId: $organizationId,
                );

            // Langkah 5 (HR-017 §3.2): Employment PLANNED, lalu SEGERA
            // diaktifkan — REVISI dari keputusan awal (§3.4 poin #1).
            // EmploymentPlacementService::createPlacement() (RM-HR-01
            // Step 7) mewajibkan Employment ACTIVE; Employment tidak
            // bisa tetap PLANNED tanpa melanggar tujuan utama §3.1
            // ("Employee selalu keluar dari transaksi ini dengan
            // Placement terpasang"). Karena itu employment_type_id di
            // $employeeData sekarang WAJIB diisi (activate() §9.1
            // langkah 7 mensyaratkannya).
            $plannedEmployment = $this->employmentLifecycleService->createPlanned(
                tenantId: $tenantId,
                employeeId: $employee['employee_id'],
                data: [
                    'employment_type_id' => $employeeData['employment_type_id'],
                    'start_date' => now()->toDateString(),
                ],
            );

            $employment = $this->employmentLifecycleService->activate(
                tenantId: $tenantId,
                employmentId: $plannedEmployment->id,
            );

            // Langkah 6: Placement TERBUKA, primary — memenuhi
            // INV-HR-012 (Employee tidak pernah tanpa Placement).
            $placement = $this->employmentPlacementService->createPlacement(
                tenantId: $tenantId,
                employmentId: $employment->id,
                data: [
                    'organizational_assignment_id' => $assignment->id,
                    'effective_from' => now()->toDateString(),
                    'is_primary' => true,
                ],
            );

            return [
                'employee_id' => $employee['employee_id'],
                'membership_id' => $employee['membership_id'],
                'person_id' => $employee['person_id'],
                'employment_id' => $employment->id,
                'employment_status' => $employment->status,
                'organizational_assignment_id' => $assignment->id,
                'employment_placement_id' => $placement->id,
            ];
        });
    }
}
