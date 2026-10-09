<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;

/**
 * Memindahkan node beserta seluruh turunannya ke induk lain (PRD-000 §10 F1).
 */
final class MoveOrganization
{
    use LocksTenantTree;

    public function __construct(
        private readonly OrganizationTree $tree,
    ) {}

    /**
     * @param  string|null  $newParentId  null = pindah langsung ke bawah Yayasan
     *
     * @throws OrganizationTreeException
     */
    public function handle(string $tenantId, string $organizationId, ?string $newParentId): Organization
    {
        return DB::transaction(function () use ($tenantId, $organizationId, $newParentId): Organization {
            $this->lockTenant($tenantId);

            $organization = $this->findInTenant($tenantId, $organizationId);

            if ($organization === null) {
                throw OrganizationTreeException::organizationNotFound();
            }

            if ($organization->parent_id === $newParentId) {
                return $organization;
            }

            $newParentLevel = 0;

            if ($newParentId !== null) {
                $newParent = $this->findInTenant($tenantId, $newParentId);

                if ($newParent === null) {
                    throw OrganizationTreeException::parentNotFound();
                }

                // Mencakup kasus "pindah ke bawah dirinya sendiri" karena closure punya baris depth 0.
                if ($this->tree->isDescendantOrSelf($tenantId, $organization->id, $newParent->id)) {
                    throw OrganizationTreeException::cycle();
                }

                if (! $newParent->isActive()) {
                    throw OrganizationTreeException::parentInactive();
                }

                $newParentLevel = $this->tree->levelOf($tenantId, $newParent->id);
            }

            // Level terdalam setelah pindah = level induk baru + 1 (node ini) + tinggi subtree-nya.
            $deepestLevelAfterMove = $newParentLevel + 1 + $this->tree->subtreeHeight($tenantId, $organization->id);

            if ($deepestLevelAfterMove > OrganizationTree::MAX_LEVEL) {
                throw OrganizationTreeException::maxDepthExceeded(OrganizationTree::MAX_LEVEL);
            }

            $this->tree->moveSubtree($tenantId, $organization->id, $newParentId);

            $organization->parent_id = $newParentId;
            $organization->save();

            return $organization;
        });
    }
}
