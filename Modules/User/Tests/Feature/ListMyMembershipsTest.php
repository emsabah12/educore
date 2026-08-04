<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Tests\TestCase;

final class ListMyMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;
    private string $otherUserId;

    private string $tenantAId;
    private string $tenantBId;
    private string $inactiveTenantId;

    private string $membershipAId;
    private string $membershipBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = Str::uuid()->toString();
        $this->otherUserId = Str::uuid()->toString();

        $this->tenantAId = Str::uuid()->toString();
        $this->tenantBId = Str::uuid()->toString();
        $this->inactiveTenantId = Str::uuid()->toString();

        $this->membershipAId = Str::uuid()->toString();
        $this->membershipBId = Str::uuid()->toString();

        $this->createUsers();
        $this->createTenants();
        $this->createMemberships();
    }

    public function test_authenticated_user_can_list_only_owned_active_memberships(): void
    {
        $user = User::query()->findOrFail(
            $this->userId,
        );

        $response = $token = app(TokenManagerInterface::class)
            ->issueToken(
                $this->userId,
                $this->tenantAId,
            );

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
    }

    public function test_unauthenticated_user_cannot_list_memberships(): void
    {
        $this
            ->getJson('/api/v1/user/my-memberships')
            ->assertUnauthorized();
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            $this->userData(
                $this->userId,
                'Membership Owner',
                'membership-owner',
            ),
            $this->userData(
                $this->otherUserId,
                'Other Membership User',
                'other-membership-user',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(
        string $id,
        string $name,
        string $emailPrefix,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'email' => sprintf(
                '%s-%s@educore.test',
                $emailPrefix,
                Str::lower(Str::random(8)),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
                $this->userId,
                $this->tenantAId,
                'employee',
                'ACTIVE',
            ),
            $this->membershipData(
                $this->membershipBId,
                $this->userId,
                $this->tenantBId,
                'teacher',
                'ACTIVE',
            ),
            $this->membershipData(
                Str::uuid()->toString(),
                $this->userId,
                $this->inactiveTenantId,
                'employee',
                'ACTIVE',
            ),
            $this->membershipData(
                Str::uuid()->toString(),
                $this->userId,
                $this->tenantAId,
                'suspended',
                'SUSPENDED',
            ),
            $this->membershipData(
                Str::uuid()->toString(),
                $this->otherUserId,
                $this->tenantAId,
                'employee',
                'ACTIVE',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipData(
        string $id,
        string $userId,
        string $tenantId,
        string $legacyRole,
        string $status,
    ): array {
        return [
            'id' => $id,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => $legacyRole,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
