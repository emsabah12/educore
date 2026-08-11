<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Models\Role;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Tenancy\Exceptions\InvalidInitialTenantAdminException;
use RuntimeException;

final class TenantProvisioningService
{
    private const ADMIN_ROLE_NAME = 'admin';

    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly ActiveUserResolverInterface $activeUserResolver,
        private readonly MembershipRoleRepositoryInterface $membershipRoleRepository,
    ) {}

    /**
     * Provision a tenant with one explicit initial tenant administrator.
     *
     * The caller supplies an existing active User account. Membership remains
     * Person-owned, while the User identifier is only used to resolve that
     * canonical Person. Tenant creation, Membership creation, and admin role
     * assignment are committed atomically.
     *
     * @param array<string, mixed> $tenantData
     *
     * @return array{
     *     tenant: array<string, mixed>,
     *     initial_admin: array{
     *         user_id: string,
     *         person_id: string,
     *         membership_id: string
     *     }
     * }
     */
    public function provision(
        array $tenantData,
        string $initialAdminUserId,
    ): array {
        $user = $this->activeUserResolver->findActiveById(
            $initialAdminUserId,
        );

        if ($user === null) {
            throw InvalidInitialTenantAdminException::unavailable();
        }

        $person = $user->person;

        if (
            $person === null
            || $person->status !== PersonStatus::ACTIVE->value
        ) {
            throw InvalidInitialTenantAdminException::unavailable();
        }

        $adminRole = Role::query()
            ->where('name', self::ADMIN_ROLE_NAME)
            ->first();

        if ($adminRole === null) {
            throw new RuntimeException(
                'Canonical admin role is unavailable.',
            );
        }

        return DB::transaction(
            function () use (
                $tenantData,
                $user,
                $person,
                $adminRole,
            ): array {
                $tenant = $this->tenantManager->createTenant(
                    $tenantData,
                );

                $tenantId = (string) $tenant['id'];

                $membership = Membership::query()->create([
                    'person_id' => (string) $person->id,
                    'tenant_id' => $tenantId,
                    'status' => 'ACTIVE',
                ]);

                $this->membershipRoleRepository->assignRole(
                    (string) $membership->id,
                    $tenantId,
                    (string) $adminRole->id,
                );

                return [
                    'tenant' => $tenant,
                    'initial_admin' => [
                        'user_id' => (string) $user->id,
                        'person_id' => (string) $person->id,
                        'membership_id' => (string) $membership->id,
                    ],
                ];
            },
        );
    }
}
