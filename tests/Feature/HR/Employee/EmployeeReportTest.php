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
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Laporan pegawai & ekspor daftar pegawai (PRD-001 §17, HR-6a). "Sekarang" 22 Okt 2026.
 *
 * Aktif: Ani (MA Unit 1, Guru, Tetap, P), Bayu (MA Unit 1, Guru, Honorer, L),
 *        Citra (SMK Unit 3, Operator, Tetap, P, lahir di Garut), Fikri (MDA Unit 1, Guru, Tetap, L, lahir di Bogor).
 * Berhenti: Dedi (MA Unit 1) berhenti 1 Okt 2026.
 */

function lpgEmployee(mixed $test, string $name, string $org, string $position, string $type, Gender $gender, ?string $birthPlace = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: $gender, birthPlace: $birthPlace,
        birthDate: $birthPlace === null ? null : '1990-05-17', phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, $type),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $org)->id,
        positionId: $catalog(CatalogKind::Position, $position),
    ))->id;
}

/**
 * @return list<list<mixed>>
 */
function lpgRows(mixed $response): array
{
    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        break;
    }

    $reader->close();

    return $rows;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');

    lpgEmployee($this, 'Ani', 'U1-MA', 'GURU', 'TETAP', Gender::Female);
    lpgEmployee($this, 'Bayu', 'U1-MA', 'GURU', 'HONORER', Gender::Male);
    lpgEmployee($this, 'Citra', 'U3-SMK', 'OPERATOR', 'TETAP', Gender::Female, 'Garut');
    lpgEmployee($this, 'Fikri', 'U1-MDA', 'GURU', 'TETAP', Gender::Male, 'Bogor');
    $dedi = lpgEmployee($this, 'Dedi', 'U1-MA', 'GURU', 'TETAP', Gender::Male);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.end', ['employee' => $dedi]), ['end_reason' => 'MENGUNDURKAN_DIRI', 'end_date' => '2026-10-01'])
        ->assertSessionHasNoErrors();
});

test('laporan seluruh yayasan: jumlah aktif, berhenti tahun ini, per lembaga/jenis/jabatan/jenis kelamin', function () {
    $this->get(route('hr.employees.report'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/report')
            ->where('report.total', 4)
            ->where('report.ended_this_year', 1)
            ->where('report.groups.organization', [
                ['label' => 'MA Unit 1', 'count' => 2],
                ['label' => 'MDA Unit 1', 'count' => 1],
                ['label' => 'SMK Unit 3', 'count' => 1],
            ])
            ->where('report.groups.employment_type', [['label' => 'Pegawai Tetap', 'count' => 3], ['label' => 'Honorer', 'count' => 1]])
            ->where('report.groups.position', [['label' => 'Guru', 'count' => 3], ['label' => 'Operator', 'count' => 1]])
            ->where('report.groups.gender', [['label' => 'Laki-laki', 'count' => 2], ['label' => 'Perempuan', 'count' => 2]]));

    $response = $this->get(route('hr.employees.report.export'));
    $response->assertOk()->assertDownload('laporan-pegawai-2026-10-22.xlsx');
    expect(lpgRows($response))->toContain(['Pegawai aktif', 4]);
});

test('laporan mengikuti cakupan: Kepala SMK Unit 3 hanya melihat pegawainya', function () {
    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.smk'))
        ->get(route('hr.employees.report'))
        ->assertInertia(fn (Assert $page) => $page->where('report.total', 1)->where('report.groups.organization.0.label', 'SMK Unit 3'));
});

test('ekspor daftar: sesuai saringan; data pribadi hanya untuk yang boleh dilihat; NIK tidak ada; dicatat di audit', function () {
    $response = $this->get(route('hr.employees.export', ['lembaga' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id]));
    $response->assertOk()->assertDownload('daftar-pegawai-2026-10-22.xlsx');
    $rows = lpgRows($response);

    expect($rows[2])->not->toContain('NIK')
        ->and(array_column(array_slice($rows, 3), 0))->toBe(['Ani', 'Bayu', 'Fikri'])
        ->and(array_slice($rows, 3)[2][13])->toBe('Bogor');

    // Koordinator MDA: melihat pegawai MDA tanpa data pribadi → kolom pribadi kosong.
    $this->flushSession();
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    $rows = lpgRows($this->actingAs($koordinator)->get(route('hr.employees.export')));

    expect(array_column(array_slice($rows, 3), 0))->toBe(['Fikri'])
        ->and(array_slice($rows, 3)[0][13] ?? '')->toBe('');

    $audits = DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEES_EXPORTED)->orderBy('created_at')->pluck('changes')->map(fn ($changes) => json_decode((string) $changes, true))->all();
    expect($audits)->toHaveCount(2)
        ->and(collect($audits)->pluck('baris')->sort()->values()->all())->toBe([1, 3])
        ->and(collect($audits)->pluck('data_pribadi')->sort()->values()->all())->toBe([0, 3]);
});

test('tanpa izin lihat pegawai: 403', function () {
    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');

    $this->actingAs($guru)->get(route('hr.employees.report'))->assertForbidden();
    $this->actingAs($guru)->get(route('hr.employees.export'))->assertForbidden();
});
