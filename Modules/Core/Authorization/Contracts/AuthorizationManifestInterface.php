<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

use Modules\Core\Authorization\DTO\CanonicalGrant;
use Modules\Core\Authorization\DTO\CanonicalPermission;
use Modules\Core\Authorization\DTO\CanonicalRole;

interface AuthorizationManifestInterface
{
    /**
     * @return list<CanonicalRole>
     */
    public function roles(): array;

    /**
     * @return list<CanonicalPermission>
     */
    public function permissions(): array;

    /**
     * @return list<CanonicalGrant>
     */
    public function grants(): array;
}
