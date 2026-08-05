<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TenantEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_generates_uuid_v7_when_id_is_missing(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'SMP Digital Indonesia',
            'subdomain' => 'smpdigital',
            'domain' => 'smpdigital.sch.id',
            'is_active' => true,
            'settings' => [
                'max_users' => 100,
            ],
        ]);

        $this->assertIsString(
            $tenant->id,
        );

        $this->assertTrue(
            UuidV7::validate($tenant->id),
            'Tenant ID must be a valid UUIDv7.',
        );

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'subdomain' => 'smpdigital',
        ]);
    }

    public function test_model_preserves_explicit_uuid_v7(): void
    {
        $explicitId = UuidV7::generate();

        $tenant = Tenant::query()->create([
            'id' => $explicitId,
            'name' => 'SMA UUID Eksplisit',
            'subdomain' => 'sma-uuid-eksplisit',
            'is_active' => true,
        ]);

        $this->assertSame(
            $explicitId,
            $tenant->id,
        );

        $this->assertDatabaseHas('tenants', [
            'id' => $explicitId,
            'subdomain' => 'sma-uuid-eksplisit',
        ]);
    }
}
