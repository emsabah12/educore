<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Ptk\PtkProfiles;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Cetak formulir A05 (PRD-001 §21.3, HR-10c-2). "Hari ini" 14 Nov 2026.
 *
 * - Ani (MA Unit 1, data resmi lembaga lengkap): NIK, lahir Bandung 17-05-1990, S1, Data PTK lengkap.
 * - Bayu (MTs Unit 1): kosong. Fikri (MDA Unit 1): tempat & tanggal lahir saja.
 */

function afpEmployee(mixed $test, string $name, string $org, ?string $birthPlace = null, ?string $nik = null, ?EducationLevel $education = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Female, birthPlace: $birthPlace,
        birthDate: $birthPlace === null ? null : '1990-05-17', phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: $education, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $org)->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'), nik: $nik,
    ))->id;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $this->ani = afpEmployee($this, 'Ani Suryani', 'U1-MA', 'Bandung', '3201015705900001', EducationLevel::S1);
    $this->bayu = afpEmployee($this, 'Bayu', 'U1-MTS');
    $this->fikri = afpEmployee($this, 'Fikri', 'U1-MDA', 'Bogor');

    $membership = (string) DB::table('memberships')->where('tenant_id', $this->tenant->id)->value('id');
    app(PtkProfiles::class)->update($this->tenant->id, $this->ani, [
        'mother_name' => 'Siti Aminah', 'sd_graduation_year' => '2002', 'ptk_function' => 'GURU', 'school_position' => 'WAKIL_KEPALA',
        'civil_servant' => 'NON_PNS', 'certified' => 'SUDAH', 'certification_year' => '2019',
    ], $membership, withPersonal: true);

    DB::table('organizations')->where('id', $this->ma)->update([
        'npsn' => '01234567', 'ownership' => 'SWASTA', 'district' => 'Cibiru', 'regency' => 'Kota Bandung', 'province' => 'Jawa Barat',
    ]);

    $this->actingAs($this->admin);
});

test('satu pegawai: formulir terisi dari data EduCore (huruf besar, jenjang dipetakan); dicatat di audit', function () {
    $this->get(route('hr.a05.print', ['pegawai' => $this->ani]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/a05')
            ->has('forms', 1)
            ->where('forms.0', [
                'employee_id' => $this->ani,
                'organization' => [
                    'id' => $this->ma, 'name' => 'MA Unit 1', 'npsn' => '01234567', 'jenjang' => 'SMA', 'ownership' => 'SWASTA',
                    'district' => 'Cibiru', 'regency' => 'Kota Bandung', 'province' => 'Jawa Barat',
                ],
                'nik' => '3201015705900001', 'name' => 'ANI SURYANI', 'birth_place' => 'BANDUNG',
                'birth' => ['day' => '17', 'month' => '05', 'year' => '1990'], 'gender' => 'P', 'mother_name' => 'SITI AMINAH',
                'education' => 'S1', 'sd_graduation_year' => '2002', 'school_position' => 'WAKIL_KEPALA', 'ptk_function' => 'GURU',
                'civil_servant' => 'NON_PNS', 'certified' => true, 'missing' => [],
            ])
            ->where('skipped', 0)
            ->has('positions', 18)
            ->where('positions.2', ['value' => 'WAKIL_KEPALA', 'label' => 'WaKa Mad/Sek'])
            ->where('backUrl', "/pegawai/{$this->ani}?tab=ptk"),
        );

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::A05_PRINTED)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['saringan' => 'satu pegawai', 'formulir' => 1, 'dilewati' => 0])
        ->and((string) $audit->changes)->not->toContain('3201015705900001');
});

test('cetak massal per saringan: isian kosong dibiarkan & didaftar; MTs → kotak SMP', function () {
    $this->get(route('hr.a05.print'))
        ->assertInertia(fn (Assert $page) => $page->has('forms', 3)->where('forms.0.name', 'ANI SURYANI')->where('backUrl', '/pegawai/rekap-ptk'));

    $this->get(route('hr.a05.print', ['lembaga' => CoreFixtures::node($this->tenant, 'U1-MTS')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forms', 1)
            ->where('forms.0.name', 'BAYU')
            ->where('forms.0.organization.jenjang', 'SMP')
            ->where('forms.0.nik', null)
            ->where('forms.0.missing', ['NPSN lembaga', 'Status lembaga', 'Wilayah lembaga', 'NIK', 'Tempat lahir', 'Tanggal lahir', 'Nama ibu kandung', 'Pendidikan', 'Tahun lulus SD', 'Fungsi PTK', 'Status PNS', 'Sertifikasi guru']),
        );

    // MDA tidak punya kotak jenjang di A05.
    $this->get(route('hr.a05.print', ['pegawai' => $this->fikri]))
        ->assertInertia(fn (Assert $page) => $page->where('forms.0.organization.jenjang', null));
});

test('tanpa hak data pribadi: satu pegawai 403, massal dilewati; di luar cakupan 404; tanpa izin lihat 403', function () {
    $this->flushSession();
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)->get(route('hr.a05.print', ['pegawai' => $this->fikri]))->assertForbidden();
    $this->actingAs($koordinator)->get(route('hr.a05.print', ['pegawai' => $this->ani]))->assertNotFound();
    $this->actingAs($koordinator)->get(route('hr.a05.print'))
        ->assertInertia(fn (Assert $page) => $page->has('forms', 0)->where('skipped', 1));

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('hr.a05.print'))->assertForbidden();
});
