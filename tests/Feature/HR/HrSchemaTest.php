<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\EmploymentType;
use Modules\HR\Domain\Catalog\Position;
use Tests\Support\CoreFixtures;

/*
 * Penjaga data kepegawaian di database (PRD-001 §4.3–§4.5). Service HR-1b memeriksa
 * aturan yang sama lebih dulu; constraint ini lapisan terakhir bila service dilewati.
 *
 * Insert yang diharapkan gagal dibungkus DB::transaction agar transaksi test tetap bisa dipakai.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->membership = CoreFixtures::member(User::factory()->create(), $this->tenant);
});

/**
 * Sisipkan baris pegawai langsung ke database (melewati service).
 *
 * @param  array<string, mixed>  $overrides
 */
function hrInsertEmployee(Tenant $tenant, Membership $membership, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('hr_employees')->insert(array_merge([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'membership_id' => $membership->id,
        'status' => 'AKTIF',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

function hrCatalogId(string $modelClass, Tenant $tenant, string $code): string
{
    /** @var class-string<EmploymentType|Position> $modelClass */
    return (string) $modelClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertPeriod(Tenant $tenant, string $employeeId, array $overrides = []): void
{
    DB::table('hr_employment_periods')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'employee_id' => $employeeId,
        'employment_type_id' => hrCatalogId(EmploymentType::class, $tenant, 'TETAP'),
        'start_date' => '2020-07-01',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertPlacement(Tenant $tenant, string $employeeId, array $overrides = []): void
{
    DB::table('hr_placements')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'employee_id' => $employeeId,
        'organization_id' => CoreFixtures::node($tenant, 'U1-MTS')->id,
        'position_id' => hrCatalogId(Position::class, $tenant, 'GURU'),
        'is_primary' => false,
        'start_date' => '2020-07-01',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function hrRejected(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
}

// ── Pegawai ───────────────────────────────────────────────────────────────────

test('satu orang hanya punya satu profil pegawai per yayasan; nomor induk dan NUPTK unik', function () {
    hrInsertEmployee($this->tenant, $this->membership, ['employee_number' => 'YYS/2026.001', 'nuptk' => '1234567890123456']);

    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership));

    $lain = CoreFixtures::member(User::factory()->create(), $this->tenant);
    hrRejected(fn () => hrInsertEmployee($this->tenant, $lain, ['employee_number' => 'YYS/2026.001']));
    hrRejected(fn () => hrInsertEmployee($this->tenant, $lain, ['nuptk' => '1234567890123456']));

    hrInsertEmployee($this->tenant, $lain, ['employee_number' => 'YYS/2026.002']);

    expect(DB::table('hr_employees')->count())->toBe(2);
});

test('database menolak NUPTK, nomor induk, pendidikan, dan status yang formatnya salah', function () {
    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership, ['nuptk' => '12345']));
    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership, ['nuptk' => '12345678901234ab']));
    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership, ['employee_number' => 'yys-001']));
    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership, ['education_level' => 'S1']));
    hrRejected(fn () => hrInsertEmployee($this->tenant, $this->membership, ['status' => 'CUTI']));

    hrInsertEmployee($this->tenant, $this->membership, ['education_level' => 'D4_S1', 'education_major' => 'Pendidikan Matematika']);

    expect(DB::table('hr_employees')->count())->toBe(1);
});

test('pegawai tidak bisa merujuk membership milik yayasan lain', function () {
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $membershipLain = CoreFixtures::member(User::factory()->create(), $tenantLain);

    hrRejected(fn () => hrInsertEmployee($this->tenant, $membershipLain));
});

// ── Periode kepegawaian ───────────────────────────────────────────────────────

test('periode: hanya satu yang terbuka, tanggal akhir tidak sebelum mulai, alasan wajib saat ditutup', function () {
    $employeeId = hrInsertEmployee($this->tenant, $this->membership);

    hrInsertPeriod($this->tenant, $employeeId, ['start_date' => '2018-01-01', 'end_date' => '2020-06-30', 'end_reason' => 'PERUBAHAN_STATUS']);
    hrInsertPeriod($this->tenant, $employeeId);

    // Periode terbuka kedua.
    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, ['start_date' => '2021-01-01']));
    // Tanggal akhir sebelum tanggal mulai.
    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, ['start_date' => '2017-01-01', 'end_date' => '2016-01-01', 'end_reason' => 'LAINNYA']));
    // Ditutup tanpa alasan, atau terbuka tetapi berisi alasan.
    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, ['start_date' => '2015-01-01', 'end_date' => '2015-12-31']));
    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, ['end_reason' => 'LAINNYA']));
    // Alasan di luar daftar.
    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, ['start_date' => '2015-01-01', 'end_date' => '2015-12-31', 'end_reason' => 'DIPECAT']));

    expect(DB::table('hr_employment_periods')->where('employee_id', $employeeId)->count())->toBe(2);
});

test('periode wajib memakai jenis dan status kepegawaian dari yayasan yang sama', function () {
    $employeeId = hrInsertEmployee($this->tenant, $this->membership);

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $this->seed(HrCatalogSeeder::class);

    hrRejected(fn () => hrInsertPeriod($this->tenant, $employeeId, [
        'employment_type_id' => hrCatalogId(EmploymentType::class, $tenantLain, 'TETAP'),
    ]));

    // Status kepegawaian boleh kosong.
    hrInsertPeriod($this->tenant, $employeeId, ['classification_id' => null]);

    expect(DB::table('hr_employment_periods')->count())->toBe(1);
});

// ── Penempatan ────────────────────────────────────────────────────────────────

test('penempatan: satu utama yang berjalan, jabatan di lembaga yang sama tidak dobel', function () {
    $employeeId = hrInsertEmployee($this->tenant, $this->membership);

    hrInsertPlacement($this->tenant, $employeeId, ['is_primary' => true]);

    // Utama kedua yang masih berjalan.
    hrRejected(fn () => hrInsertPlacement($this->tenant, $employeeId, [
        'is_primary' => true,
        'organization_id' => CoreFixtures::node($this->tenant, 'U1-MDA')->id,
        'position_id' => hrCatalogId(Position::class, $this->tenant, 'USTADZ'),
    ]));
    // Guru di MTs Unit 1 dua kali.
    hrRejected(fn () => hrInsertPlacement($this->tenant, $employeeId));
    // Tanggal akhir sebelum mulai.
    hrRejected(fn () => hrInsertPlacement($this->tenant, $employeeId, ['start_date' => '2019-01-01', 'end_date' => '2018-01-01']));

    // Penempatan kedua (bukan utama) di lembaga lain, dan riwayat lama dengan jabatan yang sama: boleh.
    hrInsertPlacement($this->tenant, $employeeId, [
        'organization_id' => CoreFixtures::node($this->tenant, 'U1-MDA')->id,
        'position_id' => hrCatalogId(Position::class, $this->tenant, 'USTADZ'),
    ]);
    hrInsertPlacement($this->tenant, $employeeId, ['start_date' => '2015-01-01', 'end_date' => '2016-01-01']);

    expect(DB::table('hr_placements')->where('employee_id', $employeeId)->count())->toBe(3);
});

test('penempatan wajib memakai lembaga dan jabatan dari yayasan yang sama', function () {
    $employeeId = hrInsertEmployee($this->tenant, $this->membership);

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $lembagaLain = CoreFixtures::unit($tenantLain, 'UNIT-LAIN');
    $this->seed(HrCatalogSeeder::class);

    hrRejected(fn () => hrInsertPlacement($this->tenant, $employeeId, ['organization_id' => $lembagaLain->id]));
    hrRejected(fn () => hrInsertPlacement($this->tenant, $employeeId, ['position_id' => hrCatalogId(Position::class, $tenantLain, 'GURU')]));

    expect(DB::table('hr_placements')->count())->toBe(0);
});

// ── Data pribadi di Person ────────────────────────────────────────────────────

test('data pribadi tersimpan di Person; nomor HP yang formatnya salah ditolak', function () {
    $person = $this->membership->person;

    $person->update([
        'birth_place' => 'Tasikmalaya',
        'birth_date' => '1990-05-17',
        'phone' => '+6281234567890',
        'address' => 'Jl. Pesantren No. 1',
    ]);

    expect($person->refresh()->birth_date?->toDateString())->toBe('1990-05-17')
        ->and($person->phone)->toBe('+6281234567890');

    hrRejected(fn () => DB::table('persons')->where('id', $person->id)->update(['phone' => '0812-3456-789']));
    hrRejected(fn () => DB::table('persons')->where('id', $person->id)->update(['phone' => '1234']));
    hrRejected(fn () => DB::table('persons')->where('id', $person->id)->update(['birth_place' => '   ']));
});
