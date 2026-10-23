<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Organization\DeactivateOrganization;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Catalog\UpdateCatalogItem;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\Placement;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\Support\TestNik;

/*
 * Tambah pegawai (PRD-001 §4.4 invarian 4, §4.5, §6, §9).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

function hrKatalogId(CatalogKind $kind, Tenant $tenant, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

/**
 * Isian form tambah pegawai yang sah (orang baru).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function hrFormPegawai(Tenant $tenant, array $overrides = []): array
{
    return array_merge([
        'mode' => 'baru',
        'name' => 'Siti Aminah',
        'nik' => TestNik::for('P', '1990-05-17'),
        'gender' => 'P',
        'birth_place' => 'Tasikmalaya',
        'birth_date' => '1990-05-17',
        'phone' => '0812-3456-7890',
        'address' => 'Jl. Pesantren No. 1',
        'employee_number' => 'yys/2026.001',
        'nuptk' => '1234 5678 9012 3456',
        'education_level' => 'D4_S1',
        'education_major' => 'Pendidikan Matematika',
        'employment_type_id' => hrKatalogId(CatalogKind::EmploymentType, $tenant, 'TETAP'),
        'classification_id' => hrKatalogId(CatalogKind::Classification, $tenant, 'GTY'),
        'start_date' => '2020-07-01',
        'organization_id' => CoreFixtures::node($tenant, 'U1-MTS')->id,
        'position_id' => hrKatalogId(CatalogKind::Position, $tenant, 'GURU'),
    ], $overrides);
}

/**
 * @return array{persons: int, memberships: int, employees: int, periods: int, placements: int}
 */
function hrJumlahData(): array
{
    return [
        'persons' => Person::query()->count(),
        'memberships' => Membership::query()->count(),
        'employees' => Employee::query()->count(),
        'periods' => EmploymentPeriod::query()->count(),
        'placements' => Placement::query()->count(),
    ];
}

// ── Form ──────────────────────────────────────────────────────────────────────

test('Admin Yayasan membuka form tambah pegawai; hanya pilihan yang aktif yang ditawarkan', function () {
    $honorer = hrKatalogId(CatalogKind::EmploymentType, $this->tenant, 'HONORER');
    app(UpdateCatalogItem::class)->setActive($this->tenant->id, CatalogKind::EmploymentType, $honorer, false);

    $this->actingAs($this->admin)
        ->get(route('hr.employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/create')
            ->where('today', BusinessClock::todayString())
            ->has('options.employmentTypes', 2)
            ->has('options.classifications', 4)
            ->has('options.positions', 9)
            ->has('options.organizations', 23)
            ->has('options.educationLevels', count(EducationLevel::cases()))
            // Anggota akun uji yang belum menjadi pegawai.
            ->has('members', 6),
        );

    // Tombol "Tambah pegawai" ditawarkan di halaman Daftar pegawai.
    $this->actingAs($this->admin)
        ->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', true));
});

test('hanya Admin SDM / Admin Yayasan di tingkat Yayasan yang boleh menambah pegawai', function () {
    $this->get(route('hr.employees.create'))->assertRedirect(route('login'));

    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.employees.create'))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.employees.store'), hrFormPegawai($this->tenant))->assertForbidden();

    $adminSdm = User::factory()->create();
    CoreFixtures::tenantRole(CoreFixtures::member($adminSdm, $this->tenant), HrAccess::ROLE_ADMIN_SDM);
    $this->actingAs($adminSdm)->get(route('hr.employees.create'))->assertOk();

    expect(Employee::query()->count())->toBe(0);
});

// ── Menyimpan ─────────────────────────────────────────────────────────────────

test('orang baru: data diri, kepegawaian, periode, dan penempatan tersimpan sekali simpan', function () {
    $usersBefore = User::query()->count();

    $response = $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->where('employee_number', 'YYS/2026.001')->sole();

    // Setelah simpan langsung ke halaman detail pegawai baru (HR-1c).
    $response->assertRedirect(route('hr.employees.show', ['employee' => $employee->id]));
    $person = Person::query()->findOrFail(Membership::query()->findOrFail($employee->membership_id)->person_id);
    $period = EmploymentPeriod::query()->where('employee_id', $employee->id)->sole();
    $placement = Placement::query()->where('employee_id', $employee->id)->sole();

    expect($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->nuptk)->toBe('1234567890123456')
        ->and($employee->education_level)->toBe(EducationLevel::D4S1)
        ->and($person->name)->toBe('Siti Aminah')
        ->and($person->gender)->toBe(Gender::Female)
        ->and($person->phone)->toBe('081234567890')
        ->and($person->birth_date?->toDateString())->toBe('1990-05-17')
        ->and($period->end_date)->toBeNull()
        ->and($period->start_date->toDateString())->toBe('2020-07-01')
        ->and($period->employment_type_id)->toBe(hrKatalogId(CatalogKind::EmploymentType, $this->tenant, 'TETAP'))
        ->and($period->classification_id)->toBe(hrKatalogId(CatalogKind::Classification, $this->tenant, 'GTY'))
        ->and($placement->is_primary)->toBeTrue()
        ->and($placement->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U1-MTS')->id)
        ->and($placement->position_id)->toBe(hrKatalogId(CatalogKind::Position, $this->tenant, 'GURU'))
        // Pegawai tidak otomatis punya akun login (PRD-001 §4.1).
        ->and(User::query()->count())->toBe($usersBefore);

    // Audit: tercatat, tetapi nilai data pribadi tidak ikut disimpan (PRD-001 §7).
    $changes = (string) DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_CREATED)->where('subject_id', $employee->id)->value('changes');

    expect($changes)->toContain('data_pribadi_diisi')
        ->and($changes)->toContain('Siti Aminah')
        ->and($changes)->not->toContain('081234567890')
        ->and($changes)->not->toContain('Tasikmalaya')
        ->and($changes)->not->toContain('Pesantren No. 1')
        ->and(DB::table('audit_logs')->where('action', AuditAction::MEMBERSHIP_GRANTED)->where('subject_id', $employee->membership_id)->count())->toBe(1)
        ->and(AuditAction::label(HrAuditAction::EMPLOYEE_CREATED))->toBe('Pegawai ditambahkan');
});

test('anggota yayasan yang sudah ada dijadikan pegawai tanpa membuat orang baru, dan tidak bisa dua kali', function () {
    $guru = DevAccounts::user('guru');
    $membership = Membership::query()->where('person_id', $guru->person_id)->where('tenant_id', $this->tenant->id)->sole();
    $before = hrJumlahData();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, [
            'mode' => 'anggota',
            'membership_id' => $membership->id,
            'name' => 'Nama Ini Diabaikan',
            'gender' => '',
            'birth_place' => '',
            'phone' => '0857 1111 2222',
        ]))
        ->assertSessionHasNoErrors();

    $person = Person::query()->findOrFail($guru->person_id);

    expect(hrJumlahData())->toMatchArray([
        'persons' => $before['persons'],
        'memberships' => $before['memberships'],
        'employees' => $before['employees'] + 1,
    ])
        ->and($person->name)->toBe('Guru MA & MDA')
        ->and($person->phone)->toBe('085711112222')
        // Kolom yang dikosongkan tidak mengubah data lama.
        ->and($person->birth_place)->toBeNull()
        ->and($person->gender)->toBe(Gender::Female);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, [
            'mode' => 'anggota',
            'membership_id' => $membership->id,
            'employee_number' => 'YYS/2026.099',
            'nuptk' => '',
        ]))
        ->assertSessionHasErrors(['membership_id' => 'Orang ini sudah terdaftar sebagai pegawai.']);

    expect(Employee::query()->count())->toBe($before['employees'] + 1);
});

test('bila satu langkah gagal, tidak ada yang tersimpan, termasuk orang dan keanggotaan baru', function () {
    $alquran = CoreFixtures::node($this->tenant, 'U1-ALQURAN');
    app(DeactivateOrganization::class)->handle($this->tenant->id, $alquran->id);
    app(UpdateCatalogItem::class)->setActive($this->tenant->id, CatalogKind::Position, hrKatalogId(CatalogKind::Position, $this->tenant, 'OPERATOR'), false);

    $before = hrJumlahData();
    $grantedBefore = DB::table('audit_logs')->where('action', AuditAction::MEMBERSHIP_GRANTED)->count();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['organization_id' => $alquran->id]))
        ->assertSessionHasErrors(['organization_id' => 'Lembaga sudah nonaktif. Pilih lembaga yang aktif.']);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['position_id' => hrKatalogId(CatalogKind::Position, $this->tenant, 'OPERATOR')]))
        ->assertSessionHasErrors('position_id');

    // Lembaga & jabatan milik yayasan lain dijawab "tidak ditemukan".
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $this->seed(HrCatalogSeeder::class);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['organization_id' => CoreFixtures::unit($tenantLain, 'UNIT-LAIN')->id]))
        ->assertSessionHasErrors(['organization_id' => 'Lembaga tidak ditemukan di yayasan ini.']);
    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['employment_type_id' => hrKatalogId(CatalogKind::EmploymentType, $tenantLain, 'TETAP')]))
        ->assertSessionHasErrors(['employment_type_id' => 'Jenis pegawai tidak ditemukan di yayasan ini.']);

    expect(hrJumlahData())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', AuditAction::MEMBERSHIP_GRANTED)->count())->toBe($grantedBefore)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_CREATED)->count())->toBe(0);
});

test('isian yang salah ditolak dengan pesan Bahasa Indonesia', function () {
    $besok = BusinessClock::today()->addDay()->toDateString();
    $before = hrJumlahData();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, [
            'name' => '',
            'gender' => '',
            'start_date' => $besok,
            'birth_date' => $besok,
            'phone' => 'nomor-saya',
            'nuptk' => '123456789012345',
            'employee_number' => 'YYS#001',
            'organization_id' => '',
        ]))
        ->assertSessionHasErrors([
            'name' => 'Nama lengkap wajib diisi.',
            'gender' => 'Jenis kelamin wajib dipilih.',
            'start_date' => 'Tanggal mulai tidak boleh di masa depan.',
            'birth_date' => 'Tanggal lahir tidak boleh di masa depan.',
            'phone' => 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.',
            'nuptk' => 'NUPTK harus 16 angka.',
            'employee_number',
            'organization_id' => 'Pilih lembaga penempatan.',
        ]);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['mode' => 'anggota', 'membership_id' => '']))
        ->assertSessionHasErrors(['membership_id' => 'Pilih anggota yayasan.']);

    expect(hrJumlahData())->toBe($before);
});

test('nomor induk dan NUPTK tidak boleh dipakai dua pegawai', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.store'), hrFormPegawai($this->tenant))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['name' => 'Ahmad Fauzi', 'gender' => 'L', 'nuptk' => '']))
        ->assertSessionHasErrors(['employee_number' => 'Nomor induk YYS/2026.001 sudah dipakai pegawai lain.']);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, ['name' => 'Ahmad Fauzi', 'gender' => 'L', 'employee_number' => 'YYS/2026.002']))
        ->assertSessionHasErrors(['nuptk' => 'NUPTK ini sudah dipakai pegawai lain.']);

    expect(Employee::query()->count())->toBe(1)
        ->and(Person::query()->where('name', 'Ahmad Fauzi')->exists())->toBeFalse();
});

test('nama sama dengan orang lama tanpa NIK tetap memunculkan peringatan walau tanggal lahir beda atau kosong', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.store'), hrFormPegawai($this->tenant))->assertSessionHasNoErrors();
    // Orang lama (data sebelum NIK diwajibkan) belum punya NIK.
    DB::table('person_identifiers')->delete();

    // Tanggal lahir beda (mis. salah ketik) dan tanggal lahir kosong.
    foreach (['1991-05-17', ''] as $birthDate) {
        $this->actingAs($this->admin)
            ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, [
                'name' => 'siti  aminah',
                'birth_date' => $birthDate,
                'employee_number' => '',
                'nuptk' => '',
            ]))
            ->assertSessionHasErrors('duplicate');

        expect(session('errors')->first('duplicate'))->toBe('Sudah ada anggota yayasan dengan nama yang sama: Siti Aminah (pegawai, lahir 1990-05-17). Bila orangnya sama, pilih "Anggota yayasan". Bila memang orang berbeda, centang konfirmasi lalu simpan ulang.');
    }

    expect(Employee::query()->count())->toBe(1);
});

test('orang lama ber-NIK dengan nama sama tetapi tanggal lahir beda tidak dianggap orang ganda', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.store'), hrFormPegawai($this->tenant))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), hrFormPegawai($this->tenant, [
            'name' => 'Siti Aminah',
            'birth_date' => '1991-05-17',
            'nik' => TestNik::for('P', '1991-05-17'),
            'employee_number' => '',
            'nuptk' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect(Employee::query()->count())->toBe(2);
});

test('nama dan tanggal lahir yang sama memunculkan peringatan; setelah dikonfirmasi boleh disimpan', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.store'), hrFormPegawai($this->tenant))->assertSessionHasNoErrors();

    $kedua = hrFormPegawai($this->tenant, ['name' => '  SITI AMINAH ', 'employee_number' => 'YYS/2026.002', 'nuptk' => '']);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), $kedua)
        ->assertSessionHasErrors('duplicate');

    expect(session('errors')->first('duplicate'))->toContain('nama dan tanggal lahir yang sama: Siti Aminah (pegawai, lahir 1990-05-17)')
        ->and(Employee::query()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), $kedua + ['confirm_duplicate' => '1'])
        ->assertSessionHasNoErrors();

    expect(Employee::query()->count())->toBe(2)
        ->and(Person::query()->whereRaw('lower(name) = ?', ['siti aminah'])->count())->toBe(2);
});
