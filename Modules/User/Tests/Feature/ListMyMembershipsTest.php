<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class ListMyMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $tenantAId;
    private string $tenantBId;
    private string $inactiveTenantId;
    private string $suspendedMembershipTenantId;
    private string $membershipAId;
    private string $membershipBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
        $this->inactiveTenantId = UuidV7::generate();
        $this->suspendedMembershipTenantId = UuidV7::generate();
        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();

        $this->createTenants();
        $this->createMemberships();
    }

    public function test_authenticated_user_can_list_only_person_owned_active_memberships(): void
    {
        $token = $this->identityTokenForUser();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/user/my-memberships');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'membership_id' => $this->membershipAId,
                'tenant_id' => $this->tenantAId,
            ])
            ->assertJsonFragment([
                'membership_id' => $this->membershipBId,
                'tenant_id' => $this->tenantBId,
            ]);

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
            'Membership discovery must not implicitly switch context or issue a Membership credential.',
        );

        $this
            ->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );
    }

    public function test_identity_user_with_zero_memberships_remains_authenticated_without_tenant_context(): void
    {
        DB::table('memberships')
            ->where(
                'person_id',
                (string) $this->user->person_id,
            )
            ->delete();

        $token = $this->identityTokenForUser();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/user/my-memberships');

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonCount(
                0,
                'data',
            );

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
        );

        $this
            ->withToken($token)
            ->getJson('/api/v1/auth/identity')
            ->assertOk()
            ->assertJsonPath(
                'data.context_type',
                'identity',
            )
            ->assertJsonPath(
                'data.user.id',
                (string) $this->user->getKey(),
            );

        $this
            ->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );
    }

    public function test_exactly_one_membership_is_discovered_without_server_side_auto_selection(): void
    {
        DB::table('memberships')
            ->where(
                'id',
                $this->membershipBId,
            )
            ->delete();

        $token = $this->identityTokenForUser();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/user/my-memberships');

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonCount(
                1,
                'data',
            )
            ->assertJsonFragment([
                'membership_id' =>
                    $this->membershipAId,
                'tenant_id' =>
                    $this->tenantAId,
            ]);

        $this->assertStringNotContainsString(
            'access_token',
            $response->getContent(),
            'Exactly one Membership must still require the canonical switch operation.',
        );

        $this
            ->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath(
                'code',
                'AUTHENTICATION_CONTEXT_DENIED',
            );

        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    public function test_unauthenticated_user_cannot_list_memberships(): void
    {
        $this
            ->getJson('/api/v1/user/my-memberships')
            ->assertUnauthorized();
    }

    private function identityTokenForUser(): string
    {
        return app(TokenManagerInterface::class)
            ->issueIdentityToken(
                (string) $this->user->getKey(),
            );
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Membership Tenant A',
                'membership-a',
                true,
            ),
            $this->tenantData(
                $this->tenantBId,
                'Membership Tenant B',
                'membership-b',
                true,
            ),
            $this->tenantData(
                $this->inactiveTenantId,
                'Inactive Membership Tenant',
                'membership-inactive',
                false,
            ),
            $this->tenantData(
                $this->suspendedMembershipTenantId,
                'Suspended Membership Tenant',
                'membership-suspended',
                true,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function tenantData(
        string $id,
        string $name,
        string $subdomainPrefix,
        bool $isActive,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'subdomain' => sprintf(
                '%s-%s',
                $subdomainPrefix,
                Str::lower(Str::random(8)),
            ),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createMemberships(): void
    {
        DB::table('memberships')->insert([
            $this->membershipData(
                $this->membershipAId,
                (string) $this->user->person_id,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->membershipBId,
                (string) $this->user->person_id,
                $this->tenantBId,
                'ACTIVE',
            ),
            $this->membershipData(
                UuidV7::generate(),
                (string) $this->user->person_id,
                $this->inactiveTenantId,
                'ACTIVE',
            ),
            $this->membershipData(
                UuidV7::generate(),
                (string) $this->user->person_id,
                $this->suspendedMembershipTenantId,
                'SUSPENDED',
            ),
            $this->membershipData(
                UuidV7::generate(),
                (string) $this->otherUser->person_id,
                $this->tenantAId,
                'ACTIVE',
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function membershipData(
        string $id,
        string $personId,
        string $tenantId,
        string $status,
    ): array {
        return [
            'id' => $id,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
