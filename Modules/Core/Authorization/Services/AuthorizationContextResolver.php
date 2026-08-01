<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Modules\Core\Authorization\Context\AuthorizationContext;
use Modules\Core\Authorization\Contracts\AuthorizationContextInterface;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;

final readonly class AuthorizationContextResolver implements AuthorizationContextResolverInterface
{
    public function __construct(
        private MembershipContextResolverInterface $membershipContextResolver,
    ) {}

    public function resolve(): AuthorizationContextInterface
    {
        $membershipContext = $this->membershipContextResolver->resolve();

        return new AuthorizationContext(
            userId: $membershipContext->userId,
            tenantId: $membershipContext->tenantId,
            membershipId: $membershipContext->membershipId,
        );
    }
}
