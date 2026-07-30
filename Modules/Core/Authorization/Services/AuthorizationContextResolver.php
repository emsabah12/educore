<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Authorization\Context\AuthorizationContext;
use Modules\Core\Authorization\Contracts\AuthorizationContextInterface;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use RuntimeException;

final readonly class AuthorizationContextResolver implements AuthorizationContextResolverInterface
{
    public function __construct(
        private AuthFactory $auth,
        private TenantContextInterface $tenantContext,
        private MembershipRepositoryInterface $membershipRepository,
    ) {}

    public function resolve(): AuthorizationContextInterface
    {
        $user = $this->auth->guard()->user();

        if ($user === null) {
            throw new RuntimeException(
                'Cannot resolve authorization context: authenticated user is required.',
            );
        }

        $userId = (string) $user->getAuthIdentifier();

        if ($userId === '') {
            throw new RuntimeException(
                'Cannot resolve authorization context: authenticated user identifier is empty.',
            );
        }

        $tenantId = $this->tenantContext->getCurrentTenantId();

        if ($tenantId === null || trim($tenantId) === '') {
            throw new RuntimeException(
                'Cannot resolve authorization context: tenant context has not been resolved.',
            );
        }

        $membership = $this->membershipRepository->findOneBy([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);

        if ($membership === null) {
            throw new RuntimeException(
                'Cannot resolve authorization context: active membership was not found.',
            );
        }

        $membershipId = (string) $membership->getKey();

        if ($membershipId === '') {
            throw new RuntimeException(
                'Cannot resolve authorization context: membership identifier is empty.',
            );
        }

        return new AuthorizationContext(
            userId: $userId,
            tenantId: $tenantId,
            membershipId: $membershipId,
        );
    }
}
