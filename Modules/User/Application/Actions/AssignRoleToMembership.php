<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\User\Application\DTO\RoleAssignmentResult;
use RuntimeException;

final readonly class AssignRoleToMembership
{
    public function __construct(
        private AuthorizationContextResolverInterface $authorizationContextResolver,
        private MembershipRepositoryInterface $membershipRepository,
        private MembershipRoleRepositoryInterface $membershipRoleRepository,
    ) {}

    /**
     * Menetapkan role kepada target membership dalam tenant actor.
     *
     * Actor membership diperoleh dari trusted authorization context.
     * Target membership diperoleh dari parameter use case.
     */
    public function execute(
        string $targetMembershipId,
        string $roleId,
    ): RoleAssignmentResult {
        $targetMembershipId = trim($targetMembershipId);
        $roleId = trim($roleId);

        if ($targetMembershipId === '') {
            throw new RuntimeException(
                'Target membership identifier is required.',
            );
        }

        if ($roleId === '') {
            throw new RuntimeException(
                'Role identifier is required.',
            );
        }

        $actorContext = $this->authorizationContextResolver->resolve();

        $targetMembership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenant(
                $targetMembershipId,
                $actorContext->tenantId(),
            );

        if ($targetMembership === null) {
            throw new RuntimeException(
                'Target membership was not found, is inactive, or belongs to another tenant.',
            );
        }

        DB::transaction(function () use (
            $targetMembershipId,
            $roleId,
        ): void {
            $this->membershipRoleRepository->assignRole(
                $targetMembershipId,
                $roleId,
            );
        });

        return new RoleAssignmentResult(
            actorUserId: $actorContext->userId(),
            actorMembershipId: $actorContext->membershipId(),
            tenantId: $actorContext->tenantId(),
            targetMembershipId: $targetMembershipId,
            roleId: $roleId,
        );
    }
}
