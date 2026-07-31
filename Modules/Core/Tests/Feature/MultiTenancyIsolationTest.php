<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Models\MockStudent;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class MultiTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure tenant-aware writes are blocked when no tenant
     * has been resolved into the canonical tenant context.
     */
    public function test_it_blocks_creation_without_tenant_context(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantContext->clear();

        $this->expectException(
            \Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException::class,
        );

        $student = new MockStudent();
        $student->id = Str::uuid()->toString();
        $student->name = 'Santri Tanpa Lembaga';
        $student->nisn = '1234567890';
        $student->status = 'ACTIVE';

        $student->save();
    }

    /**
     * Ensure tenant-aware writes automatically receive the active
     * tenant id and queries remain isolated between tenants.
     */
    public function test_it_automatically_injects_tenant_id_and_scopes_queries(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantA = Tenant::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Lembaga A',
            'subdomain' => 'a',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Lembaga B',
            'subdomain' => 'b',
            'is_active' => true,
        ]);

        $tenantContext->setCurrentTenant($tenantA);

        $studentA = new MockStudent();
        $studentA->id = Str::uuid()->toString();
        $studentA->name = 'Siswa Lembaga A';
        $studentA->nisn = '0000000001';
        $studentA->status = 'ACTIVE';
        $studentA->save();

        $this->assertEquals(
            $tenantA->id,
            $studentA->tenant_id,
        );

        $tenantContext->setCurrentTenant($tenantB);

        $studentB = new MockStudent();
        $studentB->id = Str::uuid()->toString();
        $studentB->name = 'Siswa Lembaga B';
        $studentB->nisn = '0000000002';
        $studentB->status = 'ACTIVE';
        $studentB->save();

        $this->assertEquals(
            $tenantB->id,
            $studentB->tenant_id,
        );

        $studentsInTenantB = MockStudent::query()->get();

        $this->assertCount(
            1,
            $studentsInTenantB,
        );

        $this->assertEquals(
            'Siswa Lembaga B',
            $studentsInTenantB->first()->name,
        );

        $tenantContext->setCurrentTenant($tenantA);

        $studentsInTenantA = MockStudent::query()->get();

        $this->assertCount(
            1,
            $studentsInTenantA,
        );

        $this->assertEquals(
            'Siswa Lembaga A',
            $studentsInTenantA->first()->name,
        );

        $tenantContext->clear();
    }
}
