<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

use Modules\Core\Authorization\DTO\MembershipContext;

/**
 * Resolves the canonical membership context
 * for the current application lifecycle.
 *
 * Resolver bertanggung jawab menentukan:
 *
 * User
 *   ↓
 * Active Membership
 *   ↓
 * Tenant
 *
 * Resolver tidak melakukan authorization.
 */
interface MembershipContextResolverInterface
{
    /**
     * Resolve membership context for the current request.
     *
     * @throws \RuntimeException
     */
    public function resolve(): MembershipContext;
}
