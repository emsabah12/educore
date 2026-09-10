<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class EmploymentCatalogControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $operatorEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_index_lists_employment_types_for_current_tenant(): void
    {
        $this->createEmploymentTypeFixture(
            $this->tenantId,
            'Pegawai Tetap',
            true,
        );
        $this->createEmploymentTypeFixture(
            $this->tenantId,
            'Kontrak',
            false,
        );

        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createEmploymentTypeFixture(
            $otherTenantId,
            'Milik Tenant Lain',
            true,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.employment-types.index', [], false),
            );

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue(
            $names->contains('Pegawai Tetap'),
        );
        $this->assertTrue(
            $names->contains('Kontrak'),
        );
        $this->assertFalse(
            $names->contains('Milik Tenant Lain'),
            'Employment types belonging to another tenant must never appear.',
        );
    }

    public function test_index_returns_both_active_and_inactive_entries(): void
    {
        $this->createEmploymentTypeFixture(
            $this->tenantId,
            'Honorer Nonaktif',
            false,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.employment-types.index', [], false),
            );

        $response->assertOk();

        $entry = collect($response->json('data'))
            ->firstWhere('name', 'Honorer Nonaktif');

        $this->assertNotNull($entry);
        $this->assertFalse($entry['is_active']);
    }

    public function test_index_accepts_browser_session_without_exposing_bearer(): void
    {
        config(['session.driver' => 'array']);

        $this->createEmploymentTypeFixture(
            $this->tenantId,
            'Pegawai Tetap',
            true,
        );

        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->operatorMembershipId,
            )
            ->getJson(
                route('api.v1.hr.employment-types.index', [], false),
            );

        $response->assertOk();

        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.employment-types.index', [], false),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }

    private function loginBrowserSessionAndAttachCookie(): string
    {
        $this->postJson(
            '/api/v1/browser/auth/login',
            [
                'identifier' => $this->operatorEmail,
                'password' => 'secret123',
            ],
        )->assertOk();

        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app['session']->getId(),
            );

        $this->postJson(
            sprintf(
                '/api/v1/browser/user/memberships/%s/switch',
                $this->operatorMembershipId,
            ),
        )->assertOk();

        $browserAuthState = $this->app['session']->get(
            'educore.browser_auth',
        );

        $this->assertIsArray($browserAuthState);

        $bearerCredential = $browserAuthState['membership_credentials'][$this->operatorMembershipId] ?? null;

        $this->assertIsString($bearerCredential);
        $this->assertNotSame('', trim($bearerCredential));

        return $bearerCredential;
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);
        $this->assertNotSame('', trim($cookieName));

        return $cookieName;
    }

    private function createTenantFixture(?string $tenantId = null): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId ?? $this->tenantId,
            'name' => 'Employment Catalog Tenant',
            'subdomain' => sprintf(
                'employment-catalog-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Employment Catalog Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->operatorEmail = sprintf(
            'employment-catalog-operator-%s@educore.test',
            Str::lower(Str::random(10)),
        );

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => $this->operatorEmail,
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEmploymentTypeFixture(
        string $tenantId,
        string $name,
        bool $isActive,
    ): void {
        DB::table('employment_types')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'code' => sprintf(
                'CODE-%s',
                Str::upper(Str::random(8)),
            ),
            'name' => $name,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
