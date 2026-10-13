<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Employee\Placement;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Aksi di detail pegawai (HR-1d, PRD-001 §4.3–§4.4, §6, §7).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');

    // Pegawai uji: Guru (Kontrak) di MTs Unit 1 sejak 1 Juli 2020.
    $this->employee = hrAksiPegawai($this->tenant, 'Ani', 'U1-MTS', type: 'KONTRAK', number: 'YYS/001');
});

function hrAksiKatalog(CatalogKind $kind, Tenant $tenant, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

function hrAksiPegawai(Tenant $tenant, string $name, string $organizationCode, string $position = 'GURU', string $type = 'TETAP', ?string $number = null, ?string $membershipId = null): Employee
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: $membershipId,
        name: $membershipId === null ? $name : null,
        gender: Gender::Female,
        birthPlace: null,
        birthDate: null,
        phone: '081234567890',
        address: null,
        employeeNumber: $number,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: hrAksiKatalog(CatalogKind::EmploymentType, $tenant, $type),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($tenant, $organizationCode)->id,
        positionId: hrAksiKatalog(CatalogKind::Position, $tenant, $position),
    ));
}

/**
 * @return list<Placement>
 */
function hrPenempatanBerjalan(Employee $employee): array
{
    return array_values(Placement::query()->where('employee_id', $employee->id)->whereNull('end_date')->orderBy('start_date')->get()->all());
}

// ── Ubah profil ───────────────────────────────────────────────────────────────

test('ubah profil menyimpan data diri & kepegawaian; audit hanya mencatat nama field', function () {
    $form = [
        'name' => 'Ani Rahmawati',
        'gender' => 'P',
        'birth_place' => 'Garut',
        'birth_date' => '1991-02-03',
        'phone' => '0857-1111-2222',
        'address' => '',
        'employee_number' => 'yys/001-a',
        'nuptk' => '',
        'education_level' => 'S2',
        'education_major' => 'Manajemen Pendidikan',
    ];

    $this->actingAs($this->admin)
        ->patch(route('hr.employees.update', ['employee' => $this->employee->id]), $form)
        ->assertSessionHasNoErrors();

    $employee = $this->employee->refresh();
    $person = Person::query()->findOrFail(Membership::query()->findOrFail($employee->membership_id)->person_id);

    expect($person->name)->toBe('Ani Rahmawati')
        ->and($person->phone)->toBe('085711112222')
        ->and($person->birth_place)->toBe('Garut')
        ->and($employee->employee_number)->toBe('YYS/001-A')
        ->and($employee->education_major)->toBe('Manajemen Pendidikan');

    $changes = (string) DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_UPDATED)->value('changes');

    expect($changes)->toContain('field_diubah')
        ->and($changes)->toContain('phone')
        ->and($changes)->not->toContain('085711112222')
        ->and($changes)->not->toContain('Garut');

    // Simpan ulang tanpa perubahan: tidak ada catatan audit baru.
    $this->actingAs($this->admin)->patch(route('hr.employees.update', ['employee' => $this->employee->id]), $form)->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_UPDATED)->count())->toBe(1);
});

test('ubah profil menolak nomor induk milik pegawai lain dan isian yang salah', function () {
    hrAksiPegawai($this->tenant, 'Budi', 'U1-MA', number: 'YYS/002');

    $this->actingAs($this->admin)
        ->patch(route('hr.employees.update', ['employee' => $this->employee->id]), [
            'name' => 'Ani',
            'gender' => 'P',
            'employee_number' => 'YYS/002',
        ])
        ->assertSessionHasErrors(['employee_number' => 'Nomor induk YYS/002 sudah dipakai pegawai lain.']);

    $this->actingAs($this->admin)
        ->patch(route('hr.employees.update', ['employee' => $this->employee->id]), [
            'name' => '',
            'gender' => '',
            'birth_date' => BusinessClock::today()->addDay()->toDateString(),
        ])
        ->assertSessionHasErrors(['name', 'gender', 'birth_date' => 'Tanggal lahir tidak boleh di masa depan.']);

    expect($this->employee->refresh()->employee_number)->toBe('YYS/001');
});

// ── Ganti status ──────────────────────────────────────────────────────────────

test('ganti status: periode lama ditutup sehari sebelumnya dan periode baru dibuka', function () {
    $url = route('hr.employees.status', ['employee' => $this->employee->id]);
    $tetap = hrAksiKatalog(CatalogKind::EmploymentType, $this->tenant, 'TETAP');

    // Tanggal tidak sesudah awal periode, jenis sama, atau masa depan: ditolak.
    $this->actingAs($this->admin)->post($url, ['employment_type_id' => $tetap, 'effective_date' => '2020-07-01'])
        ->assertSessionHasErrors('effective_date');
    $this->actingAs($this->admin)->post($url, ['employment_type_id' => hrAksiKatalog(CatalogKind::EmploymentType, $this->tenant, 'KONTRAK'), 'effective_date' => '2022-01-01'])
        ->assertSessionHasErrors(['employment_type_id' => 'Jenis dan status kepegawaian sama dengan yang sekarang.']);
    $this->actingAs($this->admin)->post($url, ['employment_type_id' => $tetap, 'effective_date' => BusinessClock::today()->addDay()->toDateString()])
        ->assertSessionHasErrors('effective_date');

    $this->actingAs($this->admin)
        ->post($url, [
            'employment_type_id' => $tetap,
            'classification_id' => hrAksiKatalog(CatalogKind::Classification, $this->tenant, 'GTY'),
            'effective_date' => '2022-01-01',
        ])
        ->assertSessionHasNoErrors();

    $periods = EmploymentPeriod::query()->where('employee_id', $this->employee->id)->orderBy('start_date')->get();

    expect($periods)->toHaveCount(2)
        ->and($periods[0]->end_date?->toDateString())->toBe('2021-12-31')
        ->and($periods[0]->end_reason)->toBe(EndReason::StatusChanged)
        ->and($periods[1]->start_date->toDateString())->toBe('2022-01-01')
        ->and($periods[1]->end_date)->toBeNull()
        ->and($periods[1]->employment_type_id)->toBe($tetap)
        ->and(hrPenempatanBerjalan($this->employee))->toHaveCount(1)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::STATUS_CHANGED)->count())->toBe(1);
});

// ── Penempatan ────────────────────────────────────────────────────────────────

test('tambah penempatan: tidak boleh dobel; bisa dijadikan utama', function () {
    $url = route('hr.employees.placements.store', ['employee' => $this->employee->id]);
    $ustadz = hrAksiKatalog(CatalogKind::Position, $this->tenant, 'USTADZ');
    $mda = CoreFixtures::node($this->tenant, 'U2-MDA')->id;

    $this->actingAs($this->admin)
        ->post($url, ['organization_id' => $mda, 'position_id' => $ustadz, 'start_date' => '2021-01-01'])
        ->assertSessionHasNoErrors();

    $placements = hrPenempatanBerjalan($this->employee);
    expect($placements)->toHaveCount(2)
        ->and($placements[0]->is_primary)->toBeTrue()
        ->and($placements[1]->is_primary)->toBeFalse();

    $this->actingAs($this->admin)
        ->post($url, ['organization_id' => $mda, 'position_id' => $ustadz, 'start_date' => '2021-02-01'])
        ->assertSessionHasErrors('position_id');
    $this->actingAs($this->admin)
        ->post($url, ['organization_id' => CoreFixtures::node($this->tenant, 'U3-SMK')->id, 'position_id' => $ustadz, 'start_date' => '2019-01-01'])
        ->assertSessionHasErrors('start_date');

    $this->actingAs($this->admin)
        ->post($url, ['organization_id' => CoreFixtures::node($this->tenant, 'U3-SMK')->id, 'position_id' => $ustadz, 'start_date' => '2022-01-01', 'make_primary' => '1'])
        ->assertSessionHasNoErrors();

    $primary = Placement::query()->where('employee_id', $this->employee->id)->whereNull('end_date')->where('is_primary', true)->sole();

    expect($primary->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U3-SMK')->id)
        ->and(hrPenempatanBerjalan($this->employee))->toHaveCount(3);
});

test('mutasi: penempatan lama ditutup, yang baru menjadi utama; Kepala Unit 1 tidak lagi melihatnya', function () {
    $old = hrPenempatanBerjalan($this->employee)[0];

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', ['employee' => $this->employee->id]))
        ->assertOk();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.transfer', ['employee' => $this->employee->id, 'placement' => $old->id]), [
            'organization_id' => CoreFixtures::node($this->tenant, 'U2-MA')->id,
            'position_id' => hrAksiKatalog(CatalogKind::Position, $this->tenant, 'GURU'),
            'effective_date' => '2024-01-01',
        ])
        ->assertSessionHasNoErrors();

    $current = hrPenempatanBerjalan($this->employee);

    expect($old->refresh()->end_date?->toDateString())->toBe('2023-12-31')
        ->and($current)->toHaveCount(1)
        ->and($current[0]->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U2-MA')->id)
        ->and($current[0]->is_primary)->toBeTrue()
        ->and($current[0]->start_date->toDateString())->toBe('2024-01-01');

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', ['employee' => $this->employee->id]))
        ->assertNotFound();
});

test('mengakhiri penempatan utama wajib memilih pengganti; jadikan utama memindah tanda utama', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.store', ['employee' => $this->employee->id]), [
            'organization_id' => CoreFixtures::node($this->tenant, 'U1-MDA')->id,
            'position_id' => hrAksiKatalog(CatalogKind::Position, $this->tenant, 'USTADZ'),
            'start_date' => '2021-01-01',
        ])
        ->assertSessionHasNoErrors();

    [$utama, $tambahan] = hrPenempatanBerjalan($this->employee);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.primary', ['employee' => $this->employee->id, 'placement' => $tambahan->id]))
        ->assertSessionHasNoErrors();

    expect($utama->refresh()->is_primary)->toBeFalse()
        ->and($tambahan->refresh()->is_primary)->toBeTrue();

    $endUrl = route('hr.employees.placements.end', ['employee' => $this->employee->id, 'placement' => $tambahan->id]);

    $this->actingAs($this->admin)->post($endUrl, ['end_date' => '2023-06-30'])
        ->assertSessionHasErrors(['new_primary_id' => 'Pilih penempatan pengganti sebagai penempatan utama.']);

    $this->actingAs($this->admin)->post($endUrl, ['end_date' => '2023-06-30', 'new_primary_id' => $utama->id])
        ->assertSessionHasNoErrors();

    expect($tambahan->refresh()->end_date?->toDateString())->toBe('2023-06-30')
        ->and($utama->refresh()->is_primary)->toBeTrue()
        ->and(hrPenempatanBerjalan($this->employee))->toHaveCount(1);
});

// ── Berhenti & aktif kembali ──────────────────────────────────────────────────

test('berhentikan: periode & semua penempatan ditutup; akun login dan keanggotaan tidak berubah', function () {
    $guru = DevAccounts::user('guru');
    $membership = Membership::query()->where('person_id', $guru->person_id)->where('tenant_id', $this->tenant->id)->sole();
    $employee = hrAksiPegawai($this->tenant, '', 'U1-MA', membershipId: $membership->id);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.store', ['employee' => $employee->id]), [
            'organization_id' => CoreFixtures::node($this->tenant, 'U1-MDA')->id,
            'position_id' => hrAksiKatalog(CatalogKind::Position, $this->tenant, 'USTADZ'),
            'start_date' => '2021-01-01',
        ])
        ->assertSessionHasNoErrors();

    $url = route('hr.employees.end', ['employee' => $employee->id]);

    // Alasan khusus sistem dan tanggal sebelum awal penempatan terakhir ditolak.
    $this->actingAs($this->admin)->post($url, ['end_reason' => EndReason::StatusChanged->value, 'end_date' => '2025-06-30'])
        ->assertSessionHasErrors('end_reason');
    $this->actingAs($this->admin)->post($url, ['end_reason' => EndReason::Resigned->value, 'end_date' => '2020-12-31'])
        ->assertSessionHasErrors('end_date');

    $this->actingAs($this->admin)
        ->post($url, ['end_reason' => EndReason::Resigned->value, 'end_note' => 'Pindah kota', 'end_date' => '2025-06-30'])
        ->assertSessionHasNoErrors();

    $period = EmploymentPeriod::query()->where('employee_id', $employee->id)->sole();

    expect($employee->refresh()->status)->toBe(EmployeeStatus::Ended)
        ->and($period->end_date?->toDateString())->toBe('2025-06-30')
        ->and($period->end_reason)->toBe(EndReason::Resigned)
        ->and($period->end_note)->toBe('Pindah kota')
        ->and(Placement::query()->where('employee_id', $employee->id)->whereNull('end_date')->count())->toBe(0)
        ->and(Placement::query()->where('employee_id', $employee->id)->where('end_date', '2025-06-30')->count())->toBe(2)
        // Invarian 2: akun & keanggotaan tidak disentuh.
        ->and($guru->refresh()->status)->toBe(UserStatus::Active)
        ->and($membership->refresh()->status)->toBe(MembershipStatus::Active)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYMENT_ENDED)->count())->toBe(1);

    // Pegawai yang sudah berhenti tidak bisa diberi penempatan sebelum diaktifkan kembali.
    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.store', ['employee' => $employee->id]), [
            'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id,
            'position_id' => hrAksiKatalog(CatalogKind::Position, $this->tenant, 'GURU'),
            'start_date' => '2025-08-01',
        ])
        ->assertSessionHasErrors(['form' => 'Pegawai ini sudah berhenti. Aktifkan kembali dulu.']);
});

test('aktifkan kembali: periode dan penempatan baru, tanggal harus sesudah tanggal berhenti', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.end', ['employee' => $this->employee->id]), ['end_reason' => EndReason::ContractEnded->value, 'end_date' => '2025-06-30'])
        ->assertSessionHasNoErrors();

    $url = route('hr.employees.rehire', ['employee' => $this->employee->id]);
    $form = [
        'employment_type_id' => hrAksiKatalog(CatalogKind::EmploymentType, $this->tenant, 'TETAP'),
        'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id,
        'position_id' => hrAksiKatalog(CatalogKind::Position, $this->tenant, 'GURU'),
    ];

    $this->actingAs($this->admin)->post($url, $form + ['start_date' => '2025-06-30'])
        ->assertSessionHasErrors('start_date');

    $this->actingAs($this->admin)->post($url, $form + ['start_date' => '2025-08-01'])
        ->assertSessionHasNoErrors();

    $employee = $this->employee->refresh();

    expect($employee->status)->toBe(EmployeeStatus::Active)
        ->and(EmploymentPeriod::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(EmploymentPeriod::query()->where('employee_id', $employee->id)->whereNull('end_date')->sole()->start_date->toDateString())->toBe('2025-08-01')
        ->and(hrPenempatanBerjalan($employee))->toHaveCount(1)
        ->and(hrPenempatanBerjalan($employee)[0]->is_primary)->toBeTrue();

    // Pegawai yang masih aktif tidak bisa "diaktifkan kembali".
    $this->actingAs($this->admin)->post($url, $form + ['start_date' => '2025-09-01'])
        ->assertSessionHasErrors(['form' => 'Pegawai ini masih aktif.']);
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('hanya Admin SDM / Admin Yayasan yang boleh; pegawai atau penempatan di luar yayasan dijawab 404', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)
        ->post(route('hr.employees.status', ['employee' => $this->employee->id]), [
            'employment_type_id' => hrAksiKatalog(CatalogKind::EmploymentType, $this->tenant, 'TETAP'),
            'effective_date' => '2022-01-01',
        ])
        ->assertForbidden();
    $this->actingAs($kepala)
        ->patch(route('hr.employees.update', ['employee' => $this->employee->id]), ['name' => 'Diubah', 'gender' => 'P'])
        ->assertForbidden();

    // Penempatan milik pegawai lain.
    $budi = hrAksiPegawai($this->tenant, 'Budi', 'U1-MA');
    $placementBudi = hrPenempatanBerjalan($budi)[0];

    $this->actingAs($this->admin)
        ->post(route('hr.employees.placements.primary', ['employee' => $this->employee->id, 'placement' => $placementBudi->id]))
        ->assertNotFound();

    // Pegawai yayasan lain.
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    CoreFixtures::unit($tenantLain, 'UNIT-LAIN');
    $this->seed(HrCatalogSeeder::class);
    $pegawaiLain = hrAksiPegawai($tenantLain, 'Orang Lain', 'UNIT-LAIN');

    $this->actingAs($this->admin)
        ->patch(route('hr.employees.update', ['employee' => $pegawaiLain->id]), ['name' => 'Dibajak', 'gender' => 'P'])
        ->assertNotFound();

    expect(Person::query()->where('name', 'Dibajak')->exists())->toBeFalse()
        ->and(Person::query()->where('name', 'Diubah')->exists())->toBeFalse();
});

test('detail menyediakan bahan form aksi hanya untuk pengelola', function () {
    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', ['employee' => $this->employee->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', true)
            ->where('manage.values.name', 'Ani')
            ->where('manage.values.employee_number', 'YYS/001')
            ->where('manage.values.employment_type_id', hrAksiKatalog(CatalogKind::EmploymentType, $this->tenant, 'KONTRAK'))
            ->has('manage.options.endReasons', count(EndReason::cases()) - 1),
        );

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', ['employee' => $this->employee->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('manage', null));

    expect(User::query()->where('username', 'kepala.unit1')->exists())->toBeTrue();
});
