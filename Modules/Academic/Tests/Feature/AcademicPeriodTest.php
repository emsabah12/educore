<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class AcademicPeriodTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        // Seed Tenant Induk
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Uji Waktu',
            'subdomain' => 'test-period',
            'is_active' => true
        ]);
    }

    /**
     * Menguji rantai siklus auto-deactivate melalui pemanggilan repositori terintegrasi.
     */
    public function test_can_manage_academic_periods_with_automatic_deactivation(): void
    {
        $repo = app(\Modules\Academic\Contracts\Repository\AcademicPeriodRepositoryInterface::class);

        // 1. Daftarkan tahun ajaran pertama sebagai Active
        $year1 = $repo->createYearForTenant($this->tenantId, [
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_active' => true
        ]);

        $this->assertTrue((bool) $year1['is_active']);

        // 2. Daftarkan tahun ajaran kedua sebagai Active
        $year2 = $repo->createYearForTenant($this->tenantId, [
            'name' => '2027/2028',
            'start_date' => '2027-07-01',
            'end_date' => '2028-06-30',
            'is_active' => true
        ]);

        // 3. Verifikasi: Tahun ajaran pertama wajib otomatis nonaktif!
        $checkYear1 = DB::table('academic_years')->where('id', $year1['id'])->first();
        $this->assertFalse((bool) $checkYear1->is_active);
        $this->assertTrue((bool) $year2['is_active']);
    }
}
