<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;

/*
 * Migrasi penyesuaian A05 (PRD-001 §21.3, HR-10c-1; OD-HR-28, OD-HR-29): data lama dipindah otomatis.
 * Skema dikembalikan ke bentuk lama lewat down(), diisi data lama, lalu up() dijalankan lagi.
 */

function pamMigration(): object
{
    return require base_path('modules/HR/Database/Migrations/2026_11_14_100024_align_ptk_and_education_with_a05.php');
}

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

test('fungsi PTK lama → Guru/Staf + jabatan; pendidikan D4/S1 → S1', function () {
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $employees = [];

    foreach (['GURU_KELAS', 'GURU_MAPEL', 'GURU_BK', 'KEPALA', 'TENAGA_ADMINISTRASI', 'PUSTAKAWAN', 'LABORAN', 'LAINNYA'] as $index => $function) {
        $employees[$function] = app(CreateEmployee::class)->handle(new NewEmployeeData(
            tenantId: $this->tenant->id, membershipId: null, name: "Pegawai {$index}", gender: Gender::Male, birthPlace: null,
            birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
            employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
            organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
        ))->id;
    }

    $migration = pamMigration();
    $migration->down();

    foreach ($employees as $function => $employeeId) {
        DB::table('hr_ptk_profiles')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'employee_id' => $employeeId,
            'ptk_function' => $function, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::table('hr_employees')->where('id', $employees['GURU_KELAS'])->update(['education_level' => 'D4_S1']);
    DB::table('hr_employees')->where('id', $employees['GURU_MAPEL'])->update(['education_level' => 'D3']);

    $migration->up();

    $result = DB::table('hr_ptk_profiles')->get()->mapWithKeys(fn ($row) => [
        array_search((string) $row->employee_id, $employees, true) => [$row->ptk_function, $row->school_position],
    ])->all();

    expect($result)->toEqual([
        'GURU_KELAS' => ['GURU', null],
        'GURU_MAPEL' => ['GURU', null],
        'GURU_BK' => ['GURU', null],
        'KEPALA' => ['GURU', 'KEPALA'],
        'TENAGA_ADMINISTRASI' => ['STAF', 'STAF_TU'],
        'PUSTAKAWAN' => ['STAF', 'PUSTAKAWAN'],
        'LABORAN' => ['STAF', 'LABORAN'],
        'LAINNYA' => [null, null],
    ])
        ->and(DB::table('hr_employees')->where('id', $employees['GURU_KELAS'])->value('education_level'))->toBe('S1')
        ->and(DB::table('hr_employees')->where('id', $employees['GURU_MAPEL'])->value('education_level'))->toBe('D3');

    // Pilihan baru diterima basis data; kode lama ditolak.
    DB::table('hr_employees')->where('id', $employees['GURU_BK'])->update(['education_level' => 'SMK']);
    expect(fn () => DB::table('hr_employees')->where('id', $employees['GURU_BK'])->update(['education_level' => 'D4_S1']))
        ->toThrow(QueryException::class);
});
