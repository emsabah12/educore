<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Registry;

use Modules\Core\Authorization\Contracts\AuthorizationManifestInterface;
use Modules\Core\Authorization\DTO\CanonicalGrant;
use Modules\Core\Authorization\DTO\CanonicalPermission;
use Modules\Core\Authorization\DTO\CanonicalRole;
use Modules\Core\Authorization\Exceptions\DuplicatePermissionException;
use Modules\Core\Authorization\Exceptions\DuplicateRoleException;
use Modules\Core\Authorization\Exceptions\UnknownPermissionException;
use Modules\Core\Authorization\Exceptions\UnknownRoleException;

final readonly class AuthorizationRegistryBuilder
{
    /**
     * @param iterable<AuthorizationManifestInterface> $manifests
     */
    public function __construct(
        private iterable $manifests,
    ) {}

    public function build(): AuthorizationRegistry
    {
        /** @var array<string, CanonicalRole> $roles */
        $roles = [];

        /** @var array<string, CanonicalPermission> $permissions */
        $permissions = [];

        /** @var list<CanonicalGrant> $grants */
        $grants = [];

        foreach ($this->manifests as $manifest) {

            foreach ($manifest->roles() as $role) {

                if (isset($roles[$role->name])) {
                    throw new DuplicateRoleException($role->name);
                }

                $roles[$role->name] = $role;
            }

            foreach ($manifest->permissions() as $permission) {

                if (isset($permissions[$permission->name])) {
                    throw new DuplicatePermissionException(
                        $permission->name,
                    );
                }

                $permissions[$permission->name] = $permission;
            }

            foreach ($manifest->grants() as $grant) {
                $grants[] = $grant;
            }
        }

        foreach ($grants as $grant) {

            if (! isset($roles[$grant->role])) {
                throw new UnknownRoleException(
                    $grant->role,
                );
            }

            if (! isset($permissions[$grant->permission])) {
                throw new UnknownPermissionException(
                    $grant->permission,
                );
            }
        }

        return new AuthorizationRegistry(
            roles: array_values($roles),
            permissions: array_values($permissions),
            grants: $grants,
        );
    }
}
