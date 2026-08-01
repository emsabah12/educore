<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\DTO\MembershipContext;
use Modules\Core\Authorization\Services\AuthorizationContextResolver;
use PHPUnit\Framework\TestCase;

final class AuthorizationContextResolverTest extends TestCase
{
    public function test_it_converts_membership_context_into_authorization_context(): void
    {
        $membershipContext = new MembershipContext(
            userId: '00000000-0000-0000-0000-000000000001',
            tenantId: '00000000-0000-0000-0000-000000000002',
            membershipId: '00000000-0000-0000-0000-000000000003',
        );

        $membershipContextResolver = new class(
            $membershipContext,
        ) implements MembershipContextResolverInterface {
            public function __construct(
                private readonly MembershipContext $context,
            ) {}

            public function resolve(): MembershipContext
            {
                return $this->context;
            }
        };

        $resolver = new AuthorizationContextResolver(
            $membershipContextResolver,
        );

        $authorizationContext = $resolver->resolve();

        $this->assertSame(
            $membershipContext->userId,
            $authorizationContext->userId(),
        );

        $this->assertSame(
            $membershipContext->tenantId,
            $authorizationContext->tenantId(),
        );

        $this->assertSame(
            $membershipContext->membershipId,
            $authorizationContext->membershipId(),
        );
    }
}
