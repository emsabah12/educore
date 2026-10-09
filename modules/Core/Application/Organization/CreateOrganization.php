<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Organization;

/**
 * Membuat node baru di pohon lembaga beserta baris closure-nya (PRD-000 §4, §10 F1).
 */
final class CreateOrganization
{
    use LocksTenantTree;

    private const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,49}$/';

    private const NAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly OrganizationTree $tree,
    ) {}

    /**
     * @throws OrganizationTreeException
     */
    public function handle(NewOrganizationData $data): Organization
    {
        $code = Str::upper(trim($data->code));
        $name = trim($data->name);

        $this->assertValidCode($code);
        $this->assertValidName($name);
        $this->assertValidClassification($data);

        return DB::transaction(function () use ($data, $code, $name): Organization {
            $this->lockTenant($data->tenantId);

            if ($data->parentId !== null) {
                $this->assertParentCanReceiveChild($data->tenantId, $data->parentId);
            }

            if (Organization::query()->where('tenant_id', $data->tenantId)->where('code', $code)->exists()) {
                throw OrganizationTreeException::duplicateCode($code);
            }

            try {
                $organization = Organization::query()->create([
                    'tenant_id' => $data->tenantId,
                    'parent_id' => $data->parentId,
                    'type' => $data->type,
                    'category' => $data->category,
                    'jenjang' => $data->jenjang,
                    'code' => $code,
                    'name' => $name,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Jaga-jaga bila dua request membuat kode yang sama pada saat bersamaan.
                throw OrganizationTreeException::duplicateCode($code);
            }

            $this->tree->insertLeaf($data->tenantId, $organization->id, $data->parentId);

            return $organization;
        });
    }

    private function assertParentCanReceiveChild(string $tenantId, string $parentId): void
    {
        $parent = $this->findInTenant($tenantId, $parentId);

        if ($parent === null) {
            throw OrganizationTreeException::parentNotFound();
        }

        if (! $parent->isActive()) {
            throw OrganizationTreeException::parentInactive();
        }

        $newLevel = $this->tree->levelOf($tenantId, $parent->id) + 1;

        if ($newLevel > OrganizationTree::MAX_LEVEL) {
            throw OrganizationTreeException::maxDepthExceeded(OrganizationTree::MAX_LEVEL);
        }
    }

    private function assertValidCode(string $code): void
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw OrganizationTreeException::invalidCode();
        }
    }

    private function assertValidName(string $name): void
    {
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw OrganizationTreeException::invalidName();
        }
    }

    private function assertValidClassification(NewOrganizationData $data): void
    {
        $hasClassification = $data->category !== null || $data->jenjang !== null;

        if ($data->type->requiresClassification()) {
            if ($data->category === null || $data->jenjang === null) {
                throw OrganizationTreeException::lembagaRequiresClassification();
            }

            return;
        }

        if ($hasClassification) {
            throw OrganizationTreeException::classificationOnlyForLembaga();
        }
    }
}
