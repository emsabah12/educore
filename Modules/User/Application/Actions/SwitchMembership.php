<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Modules\Auth\Token\Contracts\TokenManagerInterface;
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
        private TokenManagerInterface $tokenManager,
    ) {}

    public function execute(
        string $authenticatedUserId,
        string $targetMembershipId,
    ): MembershipSwitchResult {
        $authenticatedUserId = trim(
            $authenticatedUserId,
        );

        $targetMembershipId = trim(
            $targetMembershipId,
        );

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

        /*
         * Resolve canonical active digital account.
         *
         * Membership ownership tidak berasal dari User secara langsung,
         * tetapi dari canonical User → Person → Membership relation.
         */
        $user = $this->activeUserResolver
            ->findActiveById(
                $authenticatedUserId,
            );

        $personId = $user !== null
            ? trim(
                (string) $user->person_id,
            )
            : '';

        if ($personId === '') {
            throw new RuntimeException(
                'Akses ditolak: identity account tidak valid atau tidak aktif.',
            );
        }

        /*
         * Target Membership harus:
         *
         * - ACTIVE;
         * - dimiliki Person yang sama dengan authenticated User.
         */
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

        /*
         * Membership valid belum cukup.
         *
         * Target Tenant juga harus masih ACTIVE pada saat switch
         * dilakukan agar credential baru tidak pernah diterbitkan untuk
         * Tenant yang sudah dinonaktifkan.
         */
        $tenant = $this->tenantRuntimeResolver
            ->findActiveById(
                (string) $membership->tenant_id,
            );

        if ($tenant === null) {
            throw new RuntimeException(
                'Akses ditolak: Tenant tujuan tidak aktif.',
            );
        }

        $membershipId = (string) $membership->getKey();
        $tenantId = (string) $tenant->getKey();

        /*
         * Tenant switch merupakan authentication-context exchange.
         *
         * Credential baru membawa target Membership/Tenant.
         * Role dan permission sengaja tidak dimasukkan karena canonical
         * authorization tetap berasal dari database saat request berjalan.
         */
        $accessToken = $this->tokenManager
            ->issueMembershipToken(
                $authenticatedUserId,
                $tenantId,
                $membershipId,
            );

        return new MembershipSwitchResult(
            membershipId: $membershipId,
            tenantId: $tenantId,
            tenantName: (string) $tenant->name,
            accessToken: $accessToken,
            expiresIn: $this->tokenManager
                ->lifetimeInSeconds(),
        );
    }
}
