<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;

final class AcademicCoreTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Sekolah Master Akademik',
            'subdomain' => 'akademik-test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function test_can_interact_with_academic_classes_and_subjects_via_repository_integration(): void
    {
        $classPayload = [
            'name' => 'Kelas XI-B',
            'code' => 'K11B',
            'tingkat' => '11'
        ];

        $subjectPayload = [
            'name' => 'Bahasa Indonesia',
            'code' => 'IND-01',
            'category' => 'NASIONAL'
        ];

        // Jalankan pengujian via integrasi repository biner langsung
        $classRepo = app(\Modules\Academic\Contracts\Repository\AcademicClassRepositoryInterface::class);
        $subjectRepo = app(\Modules\Academic\Contracts\Repository\AcademicSubjectRepositoryInterface::class);

        $class = $classRepo->createForTenant($this->tenantId, $classPayload);
        $subject = $subjectRepo->createForTenant($this->tenantId, $subjectPayload);

        // Assertions kelas
        $this->assertEquals($classPayload['name'], $class['name']);
        $this->assertDatabaseHas('academic_classes', ['code' => 'K11B', 'tenant_id' => $this->tenantId]);

        // Assertions mapel
        $this->assertEquals($subjectPayload['name'], $subject['name']);
        $this->assertDatabaseHas('academic_subjects', ['code' => 'IND-01', 'tenant_id' => $this->tenantId]);
    }
}
