<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\User\Application\DTO\MembershipSwitchResult;
use RuntimeException;

final readonly class SwitchMembership
{
    public function __construct(
        private MembershipRepositoryInterface $membershipRepository,
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

        $membership = $this->membershipRepository
            ->findActiveMembershipByIdForUser(
                $targetMembershipId,
                $authenticatedUserId,
            );

        if ($membership === null) {
            throw new RuntimeException(
                'Akses ditolak: Anda tidak terdaftar atau tidak aktif pada lembaga ini.',
            );
        }

        $tenant = Tenant::query()->find(
            (string) $membership->tenant_id,
        );

        if ($tenant === null || ! (bool) $tenant->is_active) {
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
