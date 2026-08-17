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

final class SwitchMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private string $tenantAId;

    private string $tenantBId;

    private string $inactiveTenantId;

    private string $suspendedMembershipTenantId;

    private string $membershipAId;

    private string $membershipBId;

    private string $inactiveMembershipId;

    private string $inactiveTenantMembershipId;

    private string $otherUserMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->tenantAId = UuidV7::generate();
        $this->tenantBId = UuidV7::generate();
        $this->inactiveTenantId = UuidV7::generate();
        $this->suspendedMembershipTenantId = UuidV7::generate();

        $this->membershipAId = UuidV7::generate();
        $this->membershipBId = UuidV7::generate();
        $this->inactiveMembershipId = UuidV7::generate();
        $this->inactiveTenantMembershipId = UuidV7::generate();
        $this->otherUserMembershipId = UuidV7::generate();

        $this->createTenants();
        $this->createMemberships();
    }

    public function test_authenticated_user_can_switch_to_owned_active_membership_and_receive_new_token(): void
    {
        $oldToken = $this->tokenForUserA();

        $response = $this
            ->withToken($oldToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipBId,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.token_type',
                'Bearer',
            )
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipBId,
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantBId,
            )
            ->assertJsonPath(
                'data.context.tenant_name',
                'Switch Tenant B',
            );

        $newToken = $response->json(
            'data.access_token',
        );

        $this->assertIsString($newToken);
        $this->assertNotSame('', trim($newToken));

        $expiresIn = $response->json(
            'data.expires_in',
        );

        $this->assertSame(
            app(TokenManagerInterface::class)
                ->lifetimeInSeconds(),
            $expiresIn,
        );

        $claims = app(TokenManagerInterface::class)
            ->validateAndExtract(
                $newToken,
            );

        $this->assertIsArray($claims);

        $this->assertSame(
            (string) $this->userA->getKey(),
            $claims['user_id'] ?? null,
        );

        $this->assertSame(
            $this->tenantBId,
            $claims['tenant_id'] ?? null,
        );

        $this->assertSame(
            $this->membershipBId,
            $claims['membership_id'] ?? null,
        );

        $this->assertArrayNotHasKey(
            'role',
            $claims,
        );

        $this->assertArrayNotHasKey(
            'permission',
            $claims,
        );

        /*
         * Token baru harus benar-benar dapat membangun canonical
         * TenantContext untuk target Membership/Tenant.
         */
        $this
            ->withToken($newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                (string) $this->userA->getKey(),
            )
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipBId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantBId,
            );

        /*
         * Switch tidak mencabut credential lama.
         *
         * Old token tetap menjadi independent Tenant A context.
         */
        $this
            ->withToken($oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.membership.id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            );

        $this->assertStatelessSwitchContext();
    }

    public function test_authenticated_user_can_switch_between_person_owned_memberships(): void
    {
        $oldToken = $this->tokenForUserA();

        $switchToA = $this
            ->withToken($oldToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            );

        $switchToA
            ->assertOk()
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantAId,
            );

        $tokenA = $switchToA->json(
            'data.access_token',
        );

        $this->assertIsString($tokenA);

        $switchToB = $this
            ->withToken($oldToken)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipBId,
                ),
            );

        $switchToB
            ->assertOk()
            ->assertJsonPath(
                'data.context.membership_id',
                $this->membershipBId,
            )
            ->assertJsonPath(
                'data.context.tenant_id',
                $this->tenantBId,
            );

        $tokenB = $switchToB->json(
            'data.access_token',
        );

        $this->assertIsString($tokenB);

        $this
            ->withToken($tokenA)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantAId,
            );

        $this
            ->withToken($tokenB)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.tenant.id',
                $this->tenantBId,
            );

        $this->assertStatelessSwitchContext();
    }

    public function test_user_cannot_select_another_persons_membership(): void
    {
        $response = $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->otherUserMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'MEMBERSHIP_SWITCH_DENIED',
                'message' =>
                'Requested membership is not available for this user.',
            ]);

        $this->assertStatelessSwitchContext();
    }

    public function test_user_cannot_select_inactive_membership(): void
    {
        $response = $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'MEMBERSHIP_SWITCH_DENIED',
                'message' =>
                'Requested membership is not available for this user.',
            ]);
    }

    public function test_user_cannot_select_membership_of_inactive_tenant(): void
    {
        $response = $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveTenantMembershipId,
                ),
            );

        $response
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'MEMBERSHIP_SWITCH_DENIED',
                'message' =>
                'Requested membership is not available for this user.',
            ]);
    }

    public function test_unauthenticated_user_cannot_select_membership(): void
    {
        $this
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            )
            ->assertUnauthorized();
    }

    private function tokenForUserA(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                (string) $this->userA->getKey(),
                $this->tenantAId,
                [
                    'membership_id' => $this->membershipAId,
                ],
            );
    }

    private function assertStatelessSwitchContext(): void
    {
        $this->assertNull(
            session('active_membership_id'),
        );

        $this->assertNull(
            session('active_tenant_id'),
        );
    }

    private function createTenants(): void
    {
        DB::table('tenants')->insert([
            $this->tenantData(
                $this->tenantAId,
                'Switch Tenant A',
                'switch-a',
                true,
            ),
            $this->tenantData(
                $this->tenantBId,
                'Switch Tenant B',
                'switch-b',
                true,
            ),
            $this->tenantData(
                $this->inactiveTenantId,
                'Switch Inactive Tenant',
                'switch-inactive',
                false,
            ),
            $this->tenantData(
                $this->suspendedMembershipTenantId,
                'Switch Suspended Membership Tenant',
                'switch-suspended',
                true,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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
                Str::lower(
                    Str::random(8),
                ),
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
                (string) $this->userA->person_id,
                $this->tenantAId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->membershipBId,
                (string) $this->userA->person_id,
                $this->tenantBId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->inactiveMembershipId,
                (string) $this->userA->person_id,
                $this->suspendedMembershipTenantId,
                'SUSPENDED',
            ),
            $this->membershipData(
                $this->inactiveTenantMembershipId,
                (string) $this->userA->person_id,
                $this->inactiveTenantId,
                'ACTIVE',
            ),
            $this->membershipData(
                $this->otherUserMembershipId,
                (string) $this->userB->person_id,
                $this->tenantAId,
                'ACTIVE',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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
