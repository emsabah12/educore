<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Jobs\Middleware\RestoreTenantContext;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use RuntimeException;
use stdClass;
use Tests\TestCase;

final class TenantAwareJobMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_restores_active_tenant_context_and_clears_it_after_success(): void
    {
        $tenantId = $this->createTenant(
            isActive: true,
        );

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $middleware = new RestoreTenantContext(
            $tenantId,
        );

        $result = $middleware->handle(
            new stdClass(),
            function (
                object $job,
            ) use (
                $tenantContext,
                $tenantId,
            ): string {
                $this->assertInstanceOf(
                    stdClass::class,
                    $job,
                );

                $this->assertSame(
                    $tenantId,
                    $tenantContext
                        ->getCurrentTenantId(),
                );

                $this->assertSame(
                    $tenantId,
                    $tenantContext
                        ->getCurrentTenant()
                        ?->getKey(),
                );

                return 'processed';
            },
        );

        $this->assertSame(
            'processed',
            $result,
        );

        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );

        $this->assertNull(
            $tenantContext->getCurrentTenant(),
        );
    }

    public function test_middleware_clears_tenant_context_when_job_throws_exception(): void
    {
        $tenantId = $this->createTenant(
            isActive: true,
        );

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $middleware = new RestoreTenantContext(
            $tenantId,
        );

        $jobException = new RuntimeException(
            'Simulated job failure.',
        );

        try {
            $middleware->handle(
                new stdClass(),
                function () use (
                    $tenantContext,
                    $tenantId,
                    $jobException,
                ): never {
                    $this->assertSame(
                        $tenantId,
                        $tenantContext
                            ->getCurrentTenantId(),
                    );

                    throw $jobException;
                },
            );

            $this->fail(
                'Expected job exception was not thrown.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                $jobException,
                $exception,
            );
        }

        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );

        $this->assertNull(
            $tenantContext->getCurrentTenant(),
        );
    }

    public function test_middleware_rejects_inactive_tenant_before_job_runs(): void
    {
        $tenantId = $this->createTenant(
            isActive: false,
        );

        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $middleware = new RestoreTenantContext(
            $tenantId,
        );

        $jobWasExecuted = false;

        try {
            $middleware->handle(
                new stdClass(),
                function () use (
                    &$jobWasExecuted,
                ): void {
                    $jobWasExecuted = true;
                },
            );

            $this->fail(
                'Inactive tenant must not execute the job.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Queue tenant context could not be resolved.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse(
            $jobWasExecuted,
        );

        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );
    }

    public function test_middleware_rejects_malformed_tenant_identifier(): void
    {
        $middleware = new RestoreTenantContext(
            'not-a-uuid',
        );

        $jobWasExecuted = false;

        try {
            $middleware->handle(
                new stdClass(),
                function () use (
                    &$jobWasExecuted,
                ): void {
                    $jobWasExecuted = true;
                },
            );

            $this->fail(
                'Malformed tenant ID must be rejected.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Queue tenant context is invalid.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse(
            $jobWasExecuted,
        );
    }

    private function createTenant(
        bool $isActive,
    ): string {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => sprintf(
                'Queue Tenant %s',
                $tenantId,
            ),
            'subdomain' => sprintf(
                'queue-%s',
                substr(
                    str_replace(
                        '-',
                        '',
                        $tenantId,
                    ),
                    0,
                    20,
                ),
            ),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }
}
