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
use Modules\HR\Application\Ptk\PtkRecapExport;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekap PTK Simpatika (PRD-001 §21.2, HR-10b; OD-HR-25). "Hari ini" 14 Nov 2026.
 *
 * - Ani (MA Unit 1): NIK, tanggal lahir, Data PTK lengkap → lengkap 7/7; MA Unit 1 punya data resmi.
 * - Bayu (MTs Unit 1): tanpa isian apa pun → 0/7.
 * - Fikri (MDA Unit 1): tanggal & tempat lahir saja → 1/7.
 */

function prkEmployee(mixed $test, string $name, string $org, ?string $birthPlace = null, ?string $nik = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Female, birthPlace: $birthPlace,
        birthDate: $birthPlace === null ? null : '1990-05-17', phone: $birthPlace === null ? null : '081234567890', address: null,
        employeeNumber: null, nuptk: $nik === null ? null : '0123456789012345',
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $org)->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'), nik: $nik,
    ))->id;
}

/**
 * @return array<string, list<list<mixed>>> nama lembar → baris
 */
function prkSheets(mixed $response): array
{
    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    return $sheets;
}

/**
 * Baris pegawai di lembar Rekap PTK sebagai kolom → nilai.
 *
 * @return array<string, array<string, mixed>>
 */
function prkByName(array $rows): array
{
    $keys = array_keys(PtkRecapExport::COLUMNS);
    $result = [];

    foreach (array_slice($rows, 3) as $row) {
        $values = array_combine($keys, array_pad($row, count($keys), ''));
        $result[(string) $values['name']] = $values;
    }

    return $result;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');

    $this->ani = prkEmployee($this, 'Ani', 'U1-MA', 'Bandung', '3201015705900001');
    $this->bayu = prkEmployee($this, 'Bayu', 'U1-MTS');
    $this->fikri = prkEmployee($this, 'Fikri', 'U1-MDA', 'Bogor');

    $membership = (string) DB::table('memberships')->where('tenant_id', $this->tenant->id)->value('id');
    app(PtkProfiles::class)->update($this->tenant->id, $this->ani, [
        'mother_name' => 'Siti Aminah', 'sd_graduation_year' => '2002', 'ptk_function' => 'GURU_MAPEL',
        'civil_servant' => 'NON_PNS', 'certified' => 'SUDAH', 'certification_year' => '2019',
    ], $membership, withPersonal: true);

    DB::table('organizations')->where('id', CoreFixtures::node($this->tenant, 'U1-MA')->id)->update([
        'npsn' => '01234567', 'ownership' => 'SWASTA', 'district' => 'Cibiru', 'regency' => 'Kota Bandung', 'province' => 'Jawa Barat',
    ]);

    $this->actingAs($this->admin);
});

test('halaman rekap: ringkasan, kelengkapan per pegawai (belum lengkap dulu), lembaga tanpa data resmi', function () {
    $this->get(route('hr.ptk-recap.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/ptk-recap')
            ->where('recap.total', 3)
            ->where('recap.complete', 1)
            ->where('checked', ['NIK', 'Tanggal lahir', 'Nama ibu kandung', 'Tahun lulus SD', 'Fungsi PTK', 'Status PNS', 'Sertifikasi guru'])
            ->where('recap.rows.0.name', 'Bayu')
            ->where('recap.rows.0.complete', 0)
            ->where('recap.rows.0.nuptk_missing', true)
            ->where('recap.rows.1.name', 'Fikri')
            ->where('recap.rows.1.missing', ['NIK', 'Nama ibu kandung', 'Tahun lulus SD', 'Fungsi PTK', 'Status PNS', 'Sertifikasi guru'])
            ->where('recap.rows.2.name', 'Ani')
            ->where('recap.rows.2.missing', [])
            ->where('recap.rows.2.nuptk_missing', false)
            ->where('recap.organizations', [
                ['id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'name' => 'MA Unit 1', 'employees' => 1, 'missing' => []],
                ['id' => CoreFixtures::node($this->tenant, 'U1-MDA')->id, 'name' => 'MDA Unit 1', 'employees' => 1, 'missing' => ['NPSN', 'status', 'kecamatan', 'kab/kota', 'provinsi']],
                ['id' => CoreFixtures::node($this->tenant, 'U1-MTS')->id, 'name' => 'MTs Unit 1', 'employees' => 1, 'missing' => ['NPSN', 'status', 'kecamatan', 'kab/kota', 'provinsi']],
            ])
            ->where('canEditOrganizations', true),
        );

    // Saringan lembaga (beserta turunannya).
    $this->get(route('hr.ptk-recap.index', ['lembaga' => CoreFixtures::node($this->tenant, 'U1-MTS')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('recap.total', 1)->where('recap.rows.0.name', 'Bayu'));
});

test('unduh .xlsx: data PTK + data resmi lembaga + NIK & nama ibu bagi yang berhak; lembar Kelengkapan; dicatat di audit', function () {
    $response = $this->get(route('hr.ptk-recap.export'));
    $response->assertOk()->assertDownload('rekap-ptk-2026-11-14.xlsx');
    $sheets = prkSheets($response);

    expect(array_keys($sheets))->toBe(['Rekap PTK', 'Kelengkapan'])
        ->and($sheets['Rekap PTK'][2])->toBe(array_values(PtkRecapExport::COLUMNS));

    $rows = prkByName($sheets['Rekap PTK']);
    expect(array_keys($rows))->toBe(['Ani', 'Fikri', 'Bayu'])
        ->and(array_intersect_key($rows['Ani'], array_flip(['organization', 'npsn', 'jenjang', 'ownership', 'regency', 'name', 'nik', 'nuptk', 'birth_place', 'birth_date', 'mother_name', 'sd_graduation_year', 'position', 'ptk_function', 'civil_servant', 'employment_type', 'start_date', 'certified', 'certification_year', 'phone'])))
        ->toBe([
            'organization' => 'MA Unit 1', 'npsn' => '01234567', 'jenjang' => 'MA', 'ownership' => 'Swasta', 'regency' => 'Kota Bandung',
            'name' => 'Ani', 'nik' => '3201015705900001', 'nuptk' => '0123456789012345', 'birth_place' => 'Bandung', 'birth_date' => '1990-05-17',
            'mother_name' => 'Siti Aminah', 'sd_graduation_year' => '2002', 'position' => 'Guru', 'ptk_function' => 'Guru Mapel',
            'civil_servant' => 'Non-PNS', 'employment_type' => 'Pegawai Tetap', 'start_date' => '2020-07-01', 'certified' => 'Sudah',
            'certification_year' => '2019', 'phone' => '081234567890',
        ]);

    expect(array_slice($sheets['Kelengkapan'], 1))->toBe([
        ['MDA Unit 1', 'Fikri', 'NIK, Nama ibu kandung, Tahun lulus SD, Fungsi PTK, Status PNS, Sertifikasi guru, NUPTK (belum ada)'],
        ['MTs Unit 1', 'Bayu', 'NIK, Tanggal lahir, Nama ibu kandung, Tahun lulus SD, Fungsi PTK, Status PNS, Sertifikasi guru, NUPTK (belum ada)'],
    ]);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::PTK_EXPORTED)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['lembaga' => 'Semua yang terlihat', 'baris' => 3, 'data_pribadi' => 3])
        ->and((string) $audit->changes)->not->toContain('3201015705900001');
});

test('tanpa izin data pribadi: kolom pribadi kosong; cakupan lihat berlaku', function () {
    $this->flushSession();
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)->get(route('hr.ptk-recap.index'))
        ->assertInertia(fn (Assert $page) => $page->where('recap.total', 1)->where('recap.rows.0.name', 'Fikri')->where('canEditOrganizations', false));

    $rows = prkByName(prkSheets($this->actingAs($koordinator)->get(route('hr.ptk-recap.export')))['Rekap PTK']);
    expect(array_keys($rows))->toBe(['Fikri'])
        ->and([$rows['Fikri']['nik'], $rows['Fikri']['birth_place'], $rows['Fikri']['birth_date'], $rows['Fikri']['phone']])->toBe(['', '', '', ''])
        ->and($rows['Fikri']['organization'])->toBe('MDA Unit 1');

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::PTK_EXPORTED)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['lembaga' => 'Semua yang terlihat', 'baris' => 1, 'data_pribadi' => 0]);
});

test('tanpa izin lihat pegawai: 403', function () {
    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');

    $this->actingAs($guru)->get(route('hr.ptk-recap.index'))->assertForbidden();
    $this->actingAs($guru)->get(route('hr.ptk-recap.export'))->assertForbidden();
});
