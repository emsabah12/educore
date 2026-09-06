<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Contracts\MembershipLifecycleServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class MembershipLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private MembershipLifecycleServiceInterface $service;
    private string $tenantId;
    private string $personId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MembershipLifecycleServiceInterface::class);
        $this->tenantId = $this->createTenant();
        $this->personId = $this->createPerson();
    }

    public function test_ensure_active_creates_new_membership_when_none_exists(): void
    {
        $membership = $this->service->ensureActiveForPersonAndTenant($this->personId, $this->tenantId);

        $this->assertSame('ACTIVE', $membership->status);
        $this->assertDatabaseHas('memberships', [
            'person_id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_ensure_active_returns_existing_active_membership_without_duplicating(): void
    {
        $first = $this->service->ensureActiveForPersonAndTenant($this->personId, $this->tenantId);
        $second = $this->service->ensureActiveForPersonAndTenant($this->personId, $this->tenantId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            DB::table('memberships')
                ->where('person_id', $this->personId)
                ->where('tenant_id', $this->tenantId)
                ->count(),
        );
    }

    public function test_ensure_active_reactivates_inactive_membership(): void
    {
        $membershipId = UuidV7::generate();
        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'status' => 'INACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service->ensureActiveForPersonAndTenant($this->personId, $this->tenantId);

        $this->assertSame($membershipId, $result->id);
        $this->assertSame('ACTIVE', $result->status);
        $this->assertSame(
            1,
            DB::table('memberships')
                ->where('person_id', $this->personId)
                ->where('tenant_id', $this->tenantId)
                ->count(),
        );
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Membership Lifecycle Tenant',
            'subdomain' => sprintf(
                'membership-lifecycle-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createPerson(): string
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Membership Lifecycle Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personId;
    }
}
