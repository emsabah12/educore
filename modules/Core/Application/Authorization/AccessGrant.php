<?php

namespace Modules\Core\Application\Authorization;

use Modules\Core\Domain\Organization\Jenjang;

/**
 * Permission yang diberikan lewat satu penugasan, berlaku di cakupan
 * (node, filter jenjang) penugasan itu (PRD-000 §7.1).
 */
final readonly class AccessGrant
{
    /**
     * @param  array<string, true>  $permissions  set key permission
     */
    public function __construct(
        public ?string $organizationId,
        public ?Jenjang $jenjangFilter,
        public array $permissions,
    ) {}

    public function grants(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }
}
