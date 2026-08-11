<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Tests\TestCase;

final class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('core_test_tenant_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();
        Schema::dropIfExists('core_test_tenant_records');

        parent::tearDown();
    }

    public function test_it_blocks_creation_without_tenant_context(): void
    {
        app(TenantContextInterface::class)->clear();

        $this->expectException(
            TenantContextNotResolvedException::class,
        );

        CoreTenantScopedTestRecord::query()->create([
            'id' => UuidV7::generate(),
            'name' => 'Record Without Tenant',
        ]);
    }

    public function test_it_automatically_injects_tenant_id_and_scopes_queries(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantA = Tenant::query()->create([
            'name' => 'Tenant Isolation A',
            'subdomain' => 'tenant-isolation-a',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'name' => 'Tenant Isolation B',
            'subdomain' => 'tenant-isolation-b',
            'is_active' => true,
        ]);

        $tenantContext->setCurrentTenant($tenantA);

        $recordA = CoreTenantScopedTestRecord::query()->create([
            'id' => UuidV7::generate(),
            'name' => 'Tenant A Record',
        ]);

        $this->assertSame(
            (string) $tenantA->getKey(),
            (string) $recordA->tenant_id,
        );

        $tenantContext->setCurrentTenant($tenantB);

        $recordB = CoreTenantScopedTestRecord::query()->create([
            'id' => UuidV7::generate(),
            'name' => 'Tenant B Record',
        ]);

        $this->assertSame(
            (string) $tenantB->getKey(),
            (string) $recordB->tenant_id,
        );

        $recordsInTenantB = CoreTenantScopedTestRecord::query()->get();

        $this->assertCount(1, $recordsInTenantB);
        $this->assertSame(
            'Tenant B Record',
            $recordsInTenantB->first()?->name,
        );

        $tenantContext->setCurrentTenant($tenantA);

        $recordsInTenantA = CoreTenantScopedTestRecord::query()->get();

        $this->assertCount(1, $recordsInTenantA);
        $this->assertSame(
            'Tenant A Record',
            $recordsInTenantA->first()?->name,
        );
    }
}

final class CoreTenantScopedTestRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'core_test_tenant_records';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
