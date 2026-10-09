<?php

namespace Modules\Core\Application\Organization;

use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationCategory;
use Modules\Core\Domain\Organization\OrganizationType;

/**
 * Data untuk membuat node baru. Validasi aturan bisnis ada di CreateOrganization.
 */
final readonly class NewOrganizationData
{
    public function __construct(
        public string $tenantId,
        public ?string $parentId,
        public OrganizationType $type,
        public string $code,
        public string $name,
        public ?OrganizationCategory $category = null,
        public ?Jenjang $jenjang = null,
    ) {}
}
