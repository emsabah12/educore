<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;

/**
 * Menonaktifkan node. Node tidak pernah dihapus permanen agar riwayat data
 * (pegawai, siswa, nilai) yang menunjuk ke node ini tetap utuh (PRD-000 §4.5).
 */
final class DeactivateOrganization
{
    use LocksTenantTree;

    /**
     * @throws OrganizationTreeException
     */
    public function handle(string $tenantId, string $organizationId): Organization
    {
        return DB::transaction(function () use ($tenantId, $organizationId): Organization {
            $this->lockTenant($tenantId);

            $organization = $this->findInTenant($tenantId, $organizationId);

            if ($organization === null) {
                throw OrganizationTreeException::organizationNotFound();
            }

            if (! $organization->isActive()) {
                return $organization;
            }

            $hasActiveChildren = Organization::query()
                ->where('tenant_id', $tenantId)
                ->where('parent_id', $organization->id)
                ->where('status', OrganizationStatus::Active->value)
                ->exists();

            if ($hasActiveChildren) {
                throw OrganizationTreeException::hasActiveChildren();
            }

            $organization->status = OrganizationStatus::Inactive;
            $organization->save();

            return $organization;
        });
    }
}
