<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TenantContextLifecycleTest extends TestCase
{
    /**
     * Context harus konsisten selama scope aktif, tetapi tidak boleh
     * membawa state ke scope lifecycle berikutnya.
     */
    public function test_tenant_context_is_shared_within_scope_and_reset_between_scopes(): void
    {
        $tenantId = UuidV7::generate();

        $firstContext = $this->app->make(
            TenantContextInterface::class,
        );

        $firstContext->setCurrentTenant(
            new Tenant([
                'id' => $tenantId,
                'name' => 'Tenant Scope Pertama',
                'subdomain' => 'scope-pertama',
                'is_active' => true,
            ]),
        );

        $sameScopeContext = $this->app->make(
            TenantContextInterface::class,
        );

        /*
         * Scoped binding harus menghasilkan instance yang sama
         * selama lifecycle scope belum di-reset.
         */
        $this->assertSame(
            $firstContext,
            $sameScopeContext,
        );

        $this->assertSame(
            $tenantId,
            $sameScopeContext->getCurrentTenantId(),
        );

        /*
         * Mensimulasikan perpindahan request/job lifecycle.
         *
         * Laravel Octane dan queue worker melakukan scoped-instance
         * flushing pada lifecycle boundary.
         */
        $this->app->forgetScopedInstances();

        $nextScopeContext = $this->app->make(
            TenantContextInterface::class,
        );

        $this->assertNotSame(
            $firstContext,
            $nextScopeContext,
        );

        $this->assertNull(
            $nextScopeContext->getCurrentTenantId(),
        );

        $this->assertNull(
            $nextScopeContext->getCurrentTenant(),
        );
    }

    /**
     * Seluruh authorization chain yang membawa request atau tenant
     * state harus dibuat ulang pada scope berikutnya.
     */
    public function test_context_dependent_authorization_services_are_scoped(): void
    {
        $serviceContracts = [
            MembershipContextResolverInterface::class,
            AuthorizationContextResolverInterface::class,
            AuthorizationServiceInterface::class,
        ];

        /** @var array<class-string, object> $firstScopeInstances */
        $firstScopeInstances = [];

        foreach ($serviceContracts as $serviceContract) {
            $firstScopeInstances[$serviceContract] =
                $this->app->make($serviceContract);

            /*
             * Resolusi berulang dalam scope yang sama harus
             * mengembalikan instance yang sama.
             */
            $this->assertSame(
                $firstScopeInstances[$serviceContract],
                $this->app->make($serviceContract),
                sprintf(
                    '%s must be shared within the current scope.',
                    $serviceContract,
                ),
            );
        }

        $this->app->forgetScopedInstances();

        foreach ($serviceContracts as $serviceContract) {
            $nextScopeInstance = $this->app->make(
                $serviceContract,
            );

            $this->assertNotSame(
                $firstScopeInstances[$serviceContract],
                $nextScopeInstance,
                sprintf(
                    '%s must be recreated for the next scope.',
                    $serviceContract,
                ),
            );
        }
    }
}
