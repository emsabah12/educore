<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Ptk\PtkProfiles;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Data PTK pegawai untuk Simpatika (PRD-001 §21.1, HR-10a). "Hari ini" 13 Nov 2026.
 * Pegawai uji: akun `guru`, penempatan utama MA Unit 1, lahir 1990-05-01.
 */

const PTK_FULL = [
    'mother_name' => 'Siti Aminah', 'sd_graduation_year' => '2002', 'ptk_function' => 'GURU',
    'civil_servant' => 'NON_PNS', 'certified' => 'SUDAH', 'certification_year' => '2019', 'educator_certificate_number' => '19123456789012',
];

function ptkRow(mixed $test): ?stdClass
{
    $row = DB::table('hr_ptk_profiles')->where('employee_id', $test->employee)->first();

    return $row instanceof stdClass ? $row : null;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id,
        name: 'Guru', gender: Gender::Female, birthPlace: null, birthDate: '1990-05-01', phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: $ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
});

test('pegawai baru: Data PTK kosong, pilihan tersedia bagi Admin Yayasan', function () {
    $this->actingAs($this->admin)->get(route('hr.employees.show', $this->employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ptk.values.has_mother_name', false)
            ->where('ptk.values.ptk_function', null)
            ->where('ptk.withPersonal', true)
            ->has('ptk.manage.functions', 2)
            ->has('ptk.manage.positions', 18)
            ->where('ptk.manage.civilServants.1', ['value' => 'NON_PNS', 'label' => 'Non-PNS'])
            ->where('ptk.manage.yearMax', 2026),
        );
});

test('Admin Yayasan mengisi Data PTK; nama ibu terenkripsi; audit hanya nama isian', function () {
    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), PTK_FULL)->assertSessionHasNoErrors();

    $row = ptkRow($this);
    expect($row?->mother_name_encrypted)->not->toBeNull()->not->toContain('Siti')
        ->and([(int) $row?->sd_graduation_year, $row?->ptk_function, $row?->civil_servant, (bool) $row?->certified, (int) $row?->certification_year, $row?->educator_certificate_number])
        ->toBe([2002, 'GURU', 'NON_PNS', true, 2019, '19123456789012']);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::PTK_PROFILE_UPDATED)->sole();
    expect(json_decode((string) $audit->changes, true)['isian'])->toBe(['nama ibu kandung', 'tahun lulus SD', 'fungsi PTK', 'status PNS', 'sertifikasi guru', 'tahun sertifikasi', 'nomor sertifikat pendidik'])
        ->and((string) $audit->changes)->not->toContain('Siti');

    $this->actingAs($this->admin)->get(route('hr.employees.show', $this->employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ptk.values.mother_name', 'Siti Aminah')
            ->where('ptk.values.ptk_function_label', 'Guru')
            ->where('ptk.values.civil_servant_label', 'Non-PNS')
            ->where('ptk.values.certified', true),
        );

    // Simpan ulang tanpa perubahan: tidak ada audit baru. Ganti ke "Belum": tahun & nomor dikosongkan.
    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), PTK_FULL)->assertSessionHasNoErrors();
    expect(DB::table('audit_logs')->where('action', HrAuditAction::PTK_PROFILE_UPDATED)->count())->toBe(1);

    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), ['certified' => 'BELUM', 'certification_year' => '2019'] + PTK_FULL)->assertSessionHasNoErrors();
    expect([(bool) ptkRow($this)?->certified, ptkRow($this)?->certification_year, ptkRow($this)?->educator_certificate_number])->toBe([false, null, null]);
});

test('validasi tahun lulus SD, tahun sertifikasi, dan pilihan', function () {
    $patch = fn (array $data) => $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), $data);

    $patch(['sd_graduation_year' => '1939'])->assertSessionHasErrors(['sd_graduation_year' => 'Tahun lulus SD harus 4 angka, 1940 s.d. 2026.']);
    $patch(['sd_graduation_year' => '2027'])->assertSessionHasErrors(['sd_graduation_year']);
    $patch(['sd_graduation_year' => '1993'])->assertSessionHasErrors(['sd_graduation_year' => 'Tahun lulus SD tidak mungkin sebelum 1990 + 5 tahun (tahun lahir pegawai 1990).']);
    $patch(['ptk_function' => 'DOSEN'])->assertSessionHasErrors(['ptk_function' => 'Pilih fungsi PTK dari daftar.']);
    $patch(['civil_servant' => 'ASN'])->assertSessionHasErrors(['civil_servant']);
    $patch(['certified' => 'SUDAH'])->assertSessionHasErrors(['certification_year' => 'Isi tahun sertifikasi.']);
    $patch(['mother_name' => str_repeat('a', 101)])->assertSessionHasErrors(['mother_name']);

    expect(ptkRow($this))->toBeNull();
});

test('tanpa izin lihat data pribadi, nama ibu kandung tidak terbaca & tidak berubah', function () {
    $profiles = app(PtkProfiles::class);
    $membership = (string) DB::table('memberships')->where('tenant_id', $this->tenant->id)->value('id');
    $profiles->update($this->tenant->id, $this->employee, ['mother_name' => 'Siti Aminah'], $membership, withPersonal: true);
    $profiles->update($this->tenant->id, $this->employee, ['mother_name' => '', 'ptk_function' => 'STAF'], $membership, withPersonal: false);

    $limited = $profiles->forEmployee($this->tenant->id, $this->employee, withPersonal: false);
    expect([$limited['mother_name'], $limited['has_mother_name'], $limited['ptk_function']])->toBe([null, true, 'STAF'])
        ->and($profiles->forEmployee($this->tenant->id, $this->employee, withPersonal: true)['mother_name'])->toBe('Siti Aminah');
});

test('ubah Data PTK hanya Admin SDM / Admin Yayasan; pegawai lain yayasan 404', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))->patch(route('hr.employees.ptk.update', $this->employee), PTK_FULL)->assertForbidden();
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->patch(route('hr.employees.ptk.update', $this->employee), PTK_FULL)->assertForbidden();
    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', '01a1caa7-0000-7000-8000-000000000000'), PTK_FULL)->assertNotFound();

    expect(ptkRow($this))->toBeNull();
});

test('jabatan di madrasah sesuai A05: tersimpan, tampil, dan divalidasi', function () {
    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), ['ptk_function' => 'STAF', 'school_position' => 'BENDAHARA'])->assertSessionHasNoErrors();
    expect([ptkRow($this)?->ptk_function, ptkRow($this)?->school_position])->toBe(['STAF', 'BENDAHARA']);

    $this->actingAs($this->admin)->get(route('hr.employees.show', $this->employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ptk.values.ptk_function_label', 'Staf')
            ->where('ptk.values.school_position_label', 'Bendahara')
            ->where('ptk.manage.positions.1', ['value' => 'KEPALA', 'label' => 'Kepala Madrasah/Sekolah']),
        );

    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), ['school_position' => 'REKTOR'])
        ->assertSessionHasErrors(['school_position' => 'Pilih jabatan dari daftar.']);
    $this->actingAs($this->admin)->patch(route('hr.employees.ptk.update', $this->employee), ['ptk_function' => 'GURU_MAPEL'])
        ->assertSessionHasErrors(['ptk_function']);
});
