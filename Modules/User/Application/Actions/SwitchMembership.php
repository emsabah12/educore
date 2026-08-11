<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Tenancy\Contracts\TenantRuntimeResolverInterface;
use Modules\User\Application\DTO\MembershipSwitchResult;
use RuntimeException;

final readonly class SwitchMembership
{
    public function __construct(
        private MembershipRepositoryInterface $membershipRepository,
        private TenantRuntimeResolverInterface $tenantRuntimeResolver,
        private ActiveUserResolverInterface $activeUserResolver,
    ) {}

    public function execute(
        string $authenticatedUserId,
        string $targetMembershipId,
    ): MembershipSwitchResult {
        $authenticatedUserId = trim($authenticatedUserId);
        $targetMembershipId = trim($targetMembershipId);

        if ($authenticatedUserId === '') {
            throw new RuntimeException(
                'Authenticated user identifier is required.',
            );
        }

        if ($targetMembershipId === '') {
            throw new RuntimeException(
                'Target membership identifier is required.',
            );
        }

        $user = $this->activeUserResolver->findActiveById(
            $authenticatedUserId,
        );

        $personId = $user !== null
            ? trim((string) $user->person_id)
            : '';

        if ($personId === '') {
            throw new RuntimeException(
                'Akses ditolak: identity account tidak valid atau tidak aktif.',
            );
        }

        $membership = $this->membershipRepository
            ->findActiveMembershipByIdForPerson(
                $targetMembershipId,
                $personId,
            );

        if ($membership === null) {
            throw new RuntimeException(
                'Akses ditolak: Anda tidak terdaftar atau tidak aktif pada lembaga ini.',
            );
        }

        $tenant = $this->tenantRuntimeResolver
            ->findActiveById(
                (string) $membership->tenant_id,
            );

        if ($tenant === null) {
            throw new RuntimeException(
                'Akses ditolak: Tenant tujuan tidak aktif.',
            );
        }

        return new MembershipSwitchResult(
            membershipId: (string) $membership->getKey(),
            tenantId: (string) $tenant->getKey(),
            tenantName: (string) $tenant->name,
        );
    }
}
