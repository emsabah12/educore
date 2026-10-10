<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;

/**
 * Mengubah nama node (PRD-000 §4.6, OD-14). Kode node sengaja tidak bisa diubah
 * agar rujukan di data lain tetap stabil.
 */
final class RenameOrganization
{
    use LocksTenantTree;

    private const NAME_MAX_LENGTH = 200;

    /**
     * @throws OrganizationTreeException
     */
    public function handle(string $tenantId, string $organizationId, string $name): Organization
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw OrganizationTreeException::invalidName();
        }

        return DB::transaction(function () use ($tenantId, $organizationId, $name): Organization {
            $this->lockTenant($tenantId);

            $organization = $this->findInTenant($tenantId, $organizationId);

            if ($organization === null) {
                throw OrganizationTreeException::organizationNotFound();
            }

            $organization->name = $name;
            $organization->save();

            return $organization;
        });
    }
}
