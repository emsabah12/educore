<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Modules\Core\Authorization\Contracts\AccessCheckerInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;

final readonly class AccessChecker implements AccessCheckerInterface
{
    public function __construct(
        private AuthorizationServiceInterface $authorizationService,
    ) {}

    /**
     * Menentukan apakah user saat ini memiliki role tertentu.
     */
    public function hasRole(
        string $roleName,
    ): bool {
        return $this->authorizationService->hasRole(
            $roleName,
        );
    }

    /**
     * Menentukan apakah user saat ini memiliki permission tertentu.
     */
    public function can(
        string $permissionName,
    ): bool {
        return $this->authorizationService->hasPermission(
            $permissionName,
        );
    }
}
