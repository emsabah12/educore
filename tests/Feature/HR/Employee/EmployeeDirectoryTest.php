<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\Placement;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Daftar & detail pegawai: siapa melihat siapa (PRD-001 §5.3, §6, §9).
 *
 * Susunan pegawai uji:
 *   Ani    Guru di MTs Unit 1
 *   Budi   Ustadz di MDA Unit 2
 *   Citra  Guru di MTs Unit 1 + Ustadz di MDA Unit 2
 *   Dewi   Staf Biro di Biro SDM
 *   Eko    Guru (Kontrak) di SMK Unit 3
 *   Fajar  Guru di MA Unit 1, BERHENTI 30 Juni 2025
 *   Gita   dulu Guru di MA Unit 1, dimutasi ke MA Unit 2 sejak 1 Januari 2024
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $t = $this->tenant;

    $this->ani = hrBuatPegawai($t, 'Ani', 'U1-MTS', number: 'YYS/001');
    $this->budi = hrBuatPegawai($t, 'Budi', 'U2-MDA', 'USTADZ');
    $this->citra = hrBuatPegawai($t, 'Citra', 'U1-MTS');
    Placement::query()->create([
        'tenant_id' => $t->id,
        'employee_id' => $this->citra->id,
        'organization_id' => CoreFixtures::node($t, 'U2-MDA')->id,
        'position_id' => hrIdKatalog(CatalogKind::Position, $t, 'USTADZ'),
        'is_primary' => false,
        'start_date' => '2021-01-01',
    ]);
    $this->dewi = hrBuatPegawai($t, 'Dewi', 'BIRO-SDM-KEU', 'STAF-BIRO');
    $this->eko = hrBuatPegawai($t, 'Eko', 'U3-SMK', type: 'KONTRAK');

    // Fajar berhenti: periode & penempatan ditutup di tanggal yang sama (PRD-001 §4.4).
    $this->fajar = hrBuatPegawai($t, 'Fajar', 'U1-MA');
    DB::table('hr_employment_periods')->where('employee_id', $this->fajar->id)->update(['end_date' => '2025-06-30', 'end_reason' => 'MENGUNDURKAN_DIRI']);
    DB::table('hr_placements')->where('employee_id', $this->fajar->id)->update(['end_date' => '2025-06-30']);
    DB::table('hr_employees')->where('id', $this->fajar->id)->update(['status' => 'BERHENTI']);

    // Gita dimutasi: penempatan lama ditutup, penempatan baru di Unit 2.
    $this->gita = hrBuatPegawai($t, 'Gita', 'U1-MA');
    DB::table('hr_placements')->where('employee_id', $this->gita->id)->update(['end_date' => '2023-12-31']);
    Placement::query()->create([
        'tenant_id' => $t->id,
        'employee_id' => $this->gita->id,
        'organization_id' => CoreFixtures::node($t, 'U2-MA')->id,
        'position_id' => hrIdKatalog(CatalogKind::Position, $t, 'GURU'),
        'is_primary' => true,
        'start_date' => '2024-01-01',
    ]);
});

function hrIdKatalog(CatalogKind $kind, Tenant $tenant, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

function hrBuatPegawai(Tenant $tenant, string $name, string $organizationCode, string $position = 'GURU', string $type = 'TETAP', ?string $number = null): Employee
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Female,
        birthPlace: 'Tasikmalaya',
        birthDate: null,
        phone: '081234567890',
        address: null,
        employeeNumber: $number,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: hrIdKatalog(CatalogKind::EmploymentType, $tenant, $type),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($tenant, $organizationCode)->id,
        positionId: hrIdKatalog(CatalogKind::Position, $tenant, $position),
    ));
}

/**
 * Nama pegawai di halaman daftar (urut nama).
 *
 * @return list<string>
 */
function hrNamaDiDaftar(TestResponse $response): array
{
    $response->assertOk();

    /** @var array{props: array{employees: array{data: list<array{name: string}>}}} $page */
    $page = $response->viewData('page');

    return array_column($page['props']['employees']['data'], 'name');
}

// ── Daftar ────────────────────────────────────────────────────────────────────

test('Admin Yayasan melihat semua pegawai aktif; yang berhenti hanya bila diminta', function () {
    $admin = DevAccounts::user('admin.yayasan');

    $response = $this->actingAs($admin)->get(route('hr.employees.index'));

    expect(hrNamaDiDaftar($response))->toBe(['Ani', 'Budi', 'Citra', 'Dewi', 'Eko', 'Gita']);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('hr/employees/index')
        ->where('canCreate', true)
        ->where('filters.status', 'aktif')
        ->where('employees.total', 6)
        ->where('employees.data.2.other_placements', 1)
        ->where('permissions', fn ($permissions) => collect($permissions)->contains('hr.employees.view')),
    );

    expect(hrNamaDiDaftar($this->actingAs($admin)->get(route('hr.employees.index', ['status' => 'berhenti']))))->toBe(['Fajar'])
        ->and(hrNamaDiDaftar($this->actingAs($admin)->get(route('hr.employees.index', ['status' => 'semua']))))->toHaveCount(7);
});

test('Kepala Unit 1 hanya melihat pegawai yang ditempatkan di Unit 1, walau baris per halaman diperbesar', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    expect(hrNamaDiDaftar($this->actingAs($kepala)->get(route('hr.employees.index'))))->toBe(['Ani', 'Citra'])
        ->and(hrNamaDiDaftar($this->actingAs($kepala)->get(route('hr.employees.index', ['per_page' => 100]))))->toBe(['Ani', 'Citra'])
        // Fajar berhenti di MA Unit 1: tetap terlihat oleh kepala lembaga terakhirnya.
        ->and(hrNamaDiDaftar($this->actingAs($kepala)->get(route('hr.employees.index', ['status' => 'semua', 'per_page' => 1000]))))->toBe(['Ani', 'Citra', 'Fajar']);

    $this->actingAs($kepala)->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
});

test('Pimpinan Ponpes melihat seluruh unit, tetapi tidak pegawai Biro', function () {
    expect(hrNamaDiDaftar($this->actingAs(DevAccounts::user('pimpinan.ponpes'))->get(route('hr.employees.index'))))
        ->toBe(['Ani', 'Budi', 'Citra', 'Eko', 'Gita']);
});

test('Koordinator MDA melihat pegawai MDA saja, tanpa data pribadi', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    expect(hrNamaDiDaftar($this->actingAs($koordinator)->get(route('hr.employees.index'))))->toBe(['Budi', 'Citra']);

    $this->actingAs($koordinator)
        ->get(route('hr.employees.show', ['employee' => $this->budi->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/show')
            ->where('employee.name', 'Budi')
            ->where('employee.personal', null),
        );

    $this->actingAs($koordinator)->get(route('hr.employees.show', ['employee' => $this->ani->id]))->assertNotFound();
});

test('tanpa izin lihat pegawai: 403; tamu diarahkan ke login', function () {
    $this->get(route('hr.employees.index'))->assertRedirect(route('login'));

    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');

    $this->actingAs($guru)->get(route('hr.employees.index'))->assertForbidden();
    $this->actingAs($guru)->get(route('hr.employees.show', ['employee' => $this->ani->id]))->assertForbidden();
});

// ── Detail ────────────────────────────────────────────────────────────────────

test('Kepala Unit 1: pegawai Unit 2, Biro, atau yang sudah dimutasi keluar dijawab 404; pegawai Unit 1 boleh dengan data pribadi', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    foreach ([$this->budi, $this->dewi, $this->gita] as $employee) {
        $this->actingAs($kepala)->get(route('hr.employees.show', ['employee' => $employee->id]))->assertNotFound();
    }

    $this->actingAs($kepala)->get('/pegawai/bukan-uuid')->assertNotFound();

    $this->actingAs($kepala)
        ->get(route('hr.employees.show', ['employee' => $this->citra->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.personal.phone', '081234567890')
            ->where('employee.personal.birth_place', 'Tasikmalaya')
            // Penempatan di MDA Unit 2 ikut tampil karena milik pegawai yang terlihat.
            ->has('employee.placements.current', 2)
            ->where('canManage', false),
        );
});

test('detail menampilkan riwayat periode, penempatan, dan masa kerja', function () {
    $admin = DevAccounts::user('admin.yayasan');

    $this->actingAs($admin)
        ->get(route('hr.employees.show', ['employee' => $this->fajar->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.status', 'BERHENTI')
            ->where('employee.service_length', '5 tahun')
            ->where('employee.periods.0.end_reason', 'Mengundurkan diri')
            ->where('employee.has_account', false)
            // Penempatan yang ditutup bersamaan dengan berhentinya = penempatan terakhir.
            ->has('employee.placements.current', 1)
            ->has('employee.placements.history', 0),
        );

    $this->actingAs($admin)
        ->get(route('hr.employees.show', ['employee' => $this->gita->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.status', 'AKTIF')
            ->has('employee.placements.current', 1)
            ->where('employee.placements.current.0.organization_code', 'U2-MA')
            ->has('employee.placements.history', 1)
            ->where('canManage', true),
        );
});

// ── Saringan ──────────────────────────────────────────────────────────────────

test('pencarian dan saringan lembaga, jabatan, dan jenis pegawai', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $daftar = fn (array $query): array => hrNamaDiDaftar($this->actingAs($admin)->get(route('hr.employees.index', $query)));

    expect($daftar(['q' => 'cit']))->toBe(['Citra'])
        ->and($daftar(['q' => 'yys/001']))->toBe(['Ani'])
        ->and($daftar(['q' => '%']))->toBe([])
        // Lembaga ikut turunannya: Unit 1 → MTs Unit 1. Gita sudah pindah ke Unit 2.
        ->and($daftar(['lembaga' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id]))->toBe(['Ani', 'Citra'])
        ->and($daftar(['jabatan' => hrIdKatalog(CatalogKind::Position, $this->tenant, 'USTADZ')]))->toBe(['Budi', 'Citra'])
        ->and($daftar(['jenis' => hrIdKatalog(CatalogKind::EmploymentType, $this->tenant, 'KONTRAK')]))->toBe(['Eko'])
        // Nilai saringan yang tidak sah diabaikan.
        ->and($daftar(['lembaga' => 'abc', 'status' => 'xyz']))->toHaveCount(6);
});

test('pegawai tanpa penempatan hanya terlihat oleh Admin; pegawai yayasan lain dijawab 404', function () {
    $hana = hrBuatPegawai($this->tenant, 'Hana', 'U1-MTS');
    DB::table('hr_placements')->where('employee_id', $hana->id)->update(['end_date' => '2024-01-01']);

    expect(hrNamaDiDaftar($this->actingAs(DevAccounts::user('admin.yayasan'))->get(route('hr.employees.index'))))->toContain('Hana')
        ->and(hrNamaDiDaftar($this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.employees.index'))))->not->toContain('Hana');

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    CoreFixtures::unit($tenantLain, 'UNIT-LAIN');
    $this->seed(HrCatalogSeeder::class);
    $pegawaiLain = hrBuatPegawai($tenantLain, 'Orang Lain', 'UNIT-LAIN');

    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get(route('hr.employees.show', ['employee' => $pegawaiLain->id]))
        ->assertNotFound();
});
