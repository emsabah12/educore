<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentServiceInterface;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Contracts\TenantRuntimeResolverInterface;
use RuntimeException;

/**
 * Menutup celah operasional yang dicatat di komentar
 * OrganizationManagementController: sebuah tenant baru (atau tenant
 * lama yang belum pernah disentuh) TIDAK otomatis punya Organization
 * — admin-nya harus login lalu manual buat Organization + assign
 * diri sendiri dulu sebelum modul organizational-scoped (HR, dst.)
 * bisa dipakai sama sekali.
 *
 * `activate()` menutup celah itu: SEKALI per tenant (idempotent —
 * aman dipanggil berulang, baik dari provisioning tenant baru maupun
 * backfill tenant lama), buat SATU Organization default (nama =
 * nama tenant) lalu tempatkan SEMUA Membership aktif yang punya role
 * sistem `admin` di tenant itu ke situ secara org-level.
 *
 * SENGAJA no-op TOTAL (tidak membuat Organization baru ATAUPUN
 * assignment baru sama sekali) kalau tenant SUDAH punya minimal
 * satu Organization apa pun — itu sinyal tenant ini sudah "disentuh"
 * (baik oleh proses ini sebelumnya, atau oleh admin manual lewat
 * UI Kelola Organisasi), dan memaksakan assignment tambahan
 * berisiko bertentangan dengan struktur yang sudah sengaja diatur
 * sendiri oleh admin — bukan tugas proses otomatis ini untuk
 * "memperbaiki" pilihan admin, hanya untuk mengisi kekosongan total.
 */
final class TenantActivationService
{
    private const ADMIN_ROLE_NAME = 'admin';

    public function __construct(
        private readonly OrganizationalAssignmentServiceInterface $assignmentService,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantRuntimeResolverInterface $tenantRuntimeResolver,
    ) {}

    /**
     * @return Organization|null Organization default yang baru
     *     dibuat, atau null kalau tenant sudah punya Organization
     *     sebelumnya (no-op, lihat catatan kelas).
     */
    public function activate(string $tenantId): ?Organization
    {
        $tenantId = trim($tenantId);

        $tenant = $this->tenantRuntimeResolver->findActiveById(
            $tenantId,
        );

        if ($tenant === null) {
            throw new RuntimeException(
                'Tenant activation requires an active, resolvable tenant.',
            );
        }

        /*
         * Defense-in-depth terhadap state tenant context yang
         * mungkin tersisa dari pemanggil sebelumnya — pola sama
         * persis dengan RestoreTenantContext (job middleware).
         */
        $this->tenantContext->clear();
        $this->tenantContext->setCurrentTenant(
            $tenant,
        );

        try {
            return DB::transaction(
                fn(): ?Organization => $this->activateWithinTransaction(
                    $tenantId,
                    (string) $tenant->name,
                ),
            );
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function activateWithinTransaction(
        string $tenantId,
        string $tenantName,
    ): ?Organization {
        $alreadyOnboarded = Organization::query()
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($alreadyOnboarded) {
            return null;
        }

        $organization = Organization::query()->create([
            'tenant_id' => $tenantId,
            'name' => $tenantName,
            'code' => null,
            'is_active' => true,
        ]);

        foreach ($this->activeAdminMembershipIds($tenantId) as $membershipId) {
            $this->assignmentService->assignToOrganization(
                $membershipId,
                (string) $organization->id,
            );
        }

        return $organization;
    }

    /**
     * @return list<string>
     */
    private function activeAdminMembershipIds(string $tenantId): array
    {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->whereHas(
                'roles',
                fn($query) => $query
                    ->whereNull('roles.tenant_id')
                    ->where('roles.name', self::ADMIN_ROLE_NAME),
            )
            ->pluck('id')
            ->map(
                fn($id): string => (string) $id,
            )
            ->all();
    }
}
