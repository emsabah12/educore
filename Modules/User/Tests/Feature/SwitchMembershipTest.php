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

    public function test_authenticated_user_can_select_owned_active_membership(): void
    {
        $response = $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'context.membership_id',
                $this->membershipAId,
            )
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantAId,
            )
            ->assertJsonPath(
                'context.tenant_name',
                'Switch Tenant A',
            );

        $this->assertStatelessSwitchContext();
    }

    public function test_authenticated_user_can_select_between_person_owned_memberships(): void
    {
        $token = $this->tokenForUserA();

        $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipAId,
                ),
            )
            ->assertOk()
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantAId,
            );

        $this
            ->withToken($token)
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->membershipBId,
                ),
            )
            ->assertOk()
            ->assertJsonPath(
                'context.tenant_id',
                $this->tenantBId,
            );

        $this->assertStatelessSwitchContext();
    }

    public function test_user_cannot_select_another_persons_membership(): void
    {
        $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->otherUserMembershipId,
                ),
            )
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertStatelessSwitchContext();
    }

    public function test_user_cannot_select_inactive_membership(): void
    {
        $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveMembershipId,
                ),
            )
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
    }

    public function test_user_cannot_select_membership_of_inactive_tenant(): void
    {
        $this
            ->withToken($this->tokenForUserA())
            ->postJson(
                sprintf(
                    '/api/v1/user/memberships/%s/switch',
                    $this->inactiveTenantMembershipId,
                ),
            )
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
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
