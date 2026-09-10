<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectBrowserTenantContext;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Organization\Database\Seeders\OrganizationAuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class OrganizationManagementControllerTest extends TestCase
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

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(OrganizationAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            'admin',
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_index_lists_organizations_for_current_tenant(): void
    {
        $this->createOrganizationFixture($this->tenantId, 'Kantor Pusat', 'HQ');

        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createOrganizationFixture($otherTenantId, 'Milik Tenant Lain', null);

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.core.organizations.index', [], false));

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Kantor Pusat'));
        $this->assertFalse(
            $names->contains('Milik Tenant Lain'),
            'Organizations belonging to another tenant must never appear.',
        );
    }

    public function test_store_creates_new_organization(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.core.organizations.store', [], false),
                [
                    'name' => 'Kantor Pusat',
                    'code' => 'HQ',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonPath('data.name', 'Kantor Pusat');
        $response->assertJsonPath('data.code', 'HQ');
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $this->tenantId,
            'name' => 'Kantor Pusat',
            'code' => 'HQ',
        ]);
    }

    public function test_store_allows_omitted_code(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.core.organizations.store', [], false),
                [
                    'name' => 'Kantor Pusat',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonPath('data.code', null);
    }

    public function test_store_rejects_duplicate_code_within_same_tenant(): void
    {
        $this->createOrganizationFixture($this->tenantId, 'Kantor Pusat', 'HQ');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.core.organizations.store', [], false),
                [
                    'name' => 'Kantor Cabang',
                    'code' => 'HQ',
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_store_allows_same_code_across_different_tenants(): void
    {
        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createOrganizationFixture($otherTenantId, 'Punya Tenant Lain', 'HQ');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.core.organizations.store', [], false),
                [
                    'name' => 'Kantor Pusat',
                    'code' => 'HQ',
                ],
            );

        $response->assertStatus(Response::HTTP_CREATED);
    }

    public function test_index_accepts_browser_session_without_exposing_bearer(): void
    {
        config(['session.driver' => 'array']);

        $this->createOrganizationFixture($this->tenantId, 'Kantor Pusat', 'HQ');

        $bearerCredential = $this->loginBrowserSessionAndAttachCookie();

        $response = $this
            ->withHeader(
                InjectBrowserTenantContext::HEADER,
                $this->operatorMembershipId,
            )
            ->getJson(route('api.v1.core.organizations.index', [], false));

        $response->assertOk();

        $this->assertStringNotContainsString(
            $bearerCredential,
            $response->getContent(),
        );
    }

    public function test_index_is_forbidden_without_organization_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.core.organizations.index', [], false));

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
            'name' => 'Organization Management Tenant',
            'subdomain' => sprintf(
                'org-mgmt-%s',
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
            'name' => 'Organization Management Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->operatorEmail = sprintf(
            'org-mgmt-operator-%s@educore.test',
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

    private function createOrganizationFixture(
        string $tenantId,
        string $name,
        ?string $code,
    ): void {
        DB::table('organizations')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
