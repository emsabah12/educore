<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Authorization\Services\AuthorizationContextResolver;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use RuntimeException;
use Tests\TestCase;

final class AuthorizationContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantA;
    private string $tenantB;
    private string $userId;
    private string $membershipA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Str::uuid()->toString();
        $this->tenantB = Str::uuid()->toString();
        $this->userId = Str::uuid()->toString();
        $this->membershipA = Str::uuid()->toString();

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantA,
                'name' => 'Tenant Authorization A',
                'subdomain' => 'auth-a',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantB,
                'name' => 'Tenant Authorization B',
                'subdomain' => 'auth-b',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Authorization Test User',
            'email' => 'authorization-test@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipA,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_resolves_active_membership_for_authenticated_user_and_tenant(): void
    {
        $this->authenticateAsUser($this->userId);

        $this->setTenantContext($this->tenantA);

        $resolver = app(AuthorizationContextResolver::class);

        $context = $resolver->resolve();

        $this->assertSame($this->userId, $context->userId());
        $this->assertSame($this->tenantA, $context->tenantId());
        $this->assertSame($this->membershipA, $context->membershipId());
    }

    public function test_it_rejects_user_without_membership_in_current_tenant(): void
    {
        $this->authenticateAsUser($this->userId);

        $this->setTenantContext($this->tenantB);

        $resolver = app(AuthorizationContextResolver::class);

        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage(
            'Cannot resolve authorization context: active membership was not found.'
        );

        $resolver->resolve();
    }

    public function test_it_rejects_missing_tenant_context(): void
    {
        $this->authenticateAsUser($this->userId);

        $tenantContext = app(TenantContextInterface::class);
        $tenantContext->clear();

        $resolver = app(AuthorizationContextResolver::class);

        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage(
            'Cannot resolve authorization context: tenant context has not been resolved.'
        );

        $resolver->resolve();
    }

    public function test_it_rejects_missing_authenticated_user(): void
    {
        $tenantContext = app(TenantContextInterface::class);
        $tenantContext->clear();

        $this->setTenantContext($this->tenantA);

        $resolver = app(AuthorizationContextResolver::class);

        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage(
            'Cannot resolve authorization context: authenticated user is required.'
        );

        $resolver->resolve();
    }

    private function authenticateAsUser(string $userId): void
    {
        $user = DB::table('users')
            ->where('id', $userId)
            ->first();

        $this->assertNotNull($user);

        $this->actingAs(
            new class($user) extends \Illuminate\Auth\GenericUser {
                public function __construct(object $user)
                {
                    parent::__construct((array) $user);
                }
            }
        );
    }

    private function setTenantContext(string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }
}
