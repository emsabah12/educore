<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;

/**
 * Mengaktifkan kembali node yang dinonaktifkan (PRD-000 §4.6, OD-14).
 *
 * Kebalikan dari DeactivateOrganization: induknya wajib aktif lebih dulu,
 * jadi pengaktifan berjalan dari atas ke bawah.
 */
final class ReactivateOrganization
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

            if ($organization->isActive()) {
                return $organization;
            }

            if ($organization->parent_id !== null) {
                $parent = $this->findInTenant($tenantId, $organization->parent_id);

                if ($parent === null || ! $parent->isActive()) {
                    throw OrganizationTreeException::parentInactive();
                }
            }

            $organization->status = OrganizationStatus::Active;
            $organization->save();

            return $organization;
        });
    }
}
