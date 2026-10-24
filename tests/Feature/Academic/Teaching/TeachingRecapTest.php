<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\ManagePlacements;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekap presensi mengajar bulanan (PRD-002 §20.4, AC-6c).
 *
 * "Sekarang" Kamis 22 Okt 2026 13.00. SMP Unit 3, setiap Kamis:
 *   VII-A Matematika — Guru (akun `guru`, nama orang "Guru MA & MDA") 09.00–10.00
 *   VII-B IPA        — Budi IPA          11.00–12.00
 * Oktober s.d. hari ini: Kamis 1, 8, 15, 22.
 *   Guru: 1 Mengajar, 8 Kosong, 15 Digantikan Dani, 22 belum dicatat.
 *   Budi: 1 Mengajar, 8 Digantikan Guru, 15 & 22 belum dicatat.
 */

function rmgEmployee(mixed $test, string $name, string $organizationCode, ?User $user = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id,
        membershipId: $user === null ? null : Membership::query()->where('tenant_id', $test->tenant->id)->where('person_id', $user->person_id)->sole()->id,
        name: $name, gender: Gender::Male, birthPlace: null, birthDate: null, phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, $organizationCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function rmgClass(mixed $test, string $name, string $subject, string $teacherId, string $start, string $end): void
{
    $test->actingAs($test->admin)->post(route('academic.classes.store'), [
        'organization_id' => $test->smp, 'academic_year_id' => $test->year->id, 'grade' => 7, 'name' => $name,
    ])->assertSessionHasNoErrors();
    $test->actingAs($test->admin)->post(route('academic.subjects.store'), ['organization_id' => $test->smp, 'name' => $subject])->assertSessionHasNoErrors();

    $classId = (string) DB::table('ac_classes')->where('name', $name)->value('id');
    $subjectId = (string) DB::table('ac_subjects')->where('organization_id', $test->smp)->where('name', $subject)->value('id');
    $test->actingAs($test->admin)->post(route('academic.class-subjects.store', ['class' => $classId]), ['subject_ids' => [$subjectId]])->assertSessionHasNoErrors();
    $classSubjectId = (string) DB::table('ac_class_subjects')->where('class_id', $classId)->value('id');
    $test->actingAs($test->admin)->patch(route('academic.class-subjects.update', ['classSubject' => $classSubjectId]), ['teacher_employee_id' => $teacherId])->assertSessionHasNoErrors();
    $test->actingAs($test->admin)->post(route('academic.schedule.store', ['class' => $classId]), [
        'class_subject_id' => $classSubjectId, 'day_of_week' => 4, 'start_time' => $start, 'end_time' => $end,
    ])->assertSessionHasNoErrors();
}

function rmgEntry(string $className): string
{
    return (string) DB::table('ac_schedule_entries as e')->join('ac_classes as c', 'c.id', '=', 'e.class_id')->where('c.name', $className)->value('e.id');
}

/**
 * @param  array<string, string>  $data
 */
function rmgRecord(mixed $test, string $className, string $date, array $data): void
{
    $test->actingAs($test->operator)
        ->post(route('academic.teaching.store', ['entry' => rmgEntry($className)]), ['date' => $date] + $data)
        ->assertSessionHasNoErrors();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->guru = DevAccounts::user('guru');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;

    $this->guruId = rmgEmployee($this, 'Guru', 'U1-MA', $this->guru);
    $position = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id');
    app(ManagePlacements::class)->add($this->tenant->id, $this->guruId, $this->smp, $position, '2020-07-01', false);
    $budi = rmgEmployee($this, 'Budi IPA', 'U3-SMP');
    $dani = rmgEmployee($this, 'Dani Piket', 'U3-SMP');

    rmgClass($this, 'VII-A', 'Matematika', $this->guruId, '09:00', '10:00');
    rmgClass($this, 'VII-B', 'IPA', $budi, '11:00', '12:00');
    $this->flushSession();

    rmgRecord($this, 'VII-A', '2026-10-01', ['status' => 'MENGAJAR']);
    rmgRecord($this, 'VII-A', '2026-10-08', ['status' => 'KOSONG', 'absence_reason' => 'IZIN']);
    rmgRecord($this, 'VII-A', '2026-10-15', ['status' => 'DIGANTIKAN', 'absence_reason' => 'SAKIT', 'substitute_employee_id' => $dani]);
    rmgRecord($this, 'VII-B', '2026-10-01', ['status' => 'MENGAJAR']);
    rmgRecord($this, 'VII-B', '2026-10-08', ['status' => 'DIGANTIKAN', 'absence_reason' => 'DINAS_LUAR', 'substitute_employee_id' => $this->guruId]);
});

test('rekap bulanan per guru: terjadwal sampai hari ini, mengajar, digantikan, kosong, belum dicatat, menggantikan', function () {
    $row = fn (string $name, int $scheduled, int $taught, int $substituted, int $empty, int $unrecorded, int $substituting, ?float $percent): array => [
        'name' => $name, 'scheduled' => $scheduled, 'taught' => $taught, 'substituted' => $substituted, 'empty' => $empty,
        'unrecorded' => $unrecorded, 'substituting' => $substituting, 'percent' => $percent,
    ];
    $strip = fn (array $rows): array => array_map(fn (array $item): array => array_diff_key($item, ['employee_id' => true]), $rows);

    $this->actingAs($this->operator)
        ->get(route('academic.teaching.recap', ['bulan' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/teaching/recap')
            ->where('month', '2026-10')
            ->where('label', 'Oktober 2026')
            ->where('until', '2026-10-22')
            ->where('rows', fn ($rows) => $strip(collect($rows)->all()) == [
                $row('Budi IPA', 4, 1, 1, 0, 2, 0, 25.0),
                $row('Dani Piket', 0, 0, 0, 0, 0, 1, null),
                // Akun `guru` sudah punya nama orang sendiri (bukan nama di NewEmployeeData).
                $row('Guru MA & MDA', 4, 1, 1, 1, 1, 1, 25.0),
            ])
            ->where('totals.scheduled', 8)
            ->where('totals.substituting', 2));

    // Bulan lalu: semua belum dicatat. Bulan yang akan datang → bulan berjalan.
    $this->actingAs($this->operator)
        ->get(route('academic.teaching.recap', ['bulan' => '2026-09']))
        ->assertInertia(fn (Assert $page) => $page->where('until', '2026-09-30')->where('totals.scheduled', 8)->where('totals.unrecorded', 8));
    $this->actingAs($this->operator)
        ->get(route('academic.teaching.recap', ['bulan' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
});

test('unduh rekap .xlsx: lembar Rekap & Rincian tanpa keterangan', function () {
    $response = $this->actingAs($this->operator)->get(route('academic.teaching.recap.export', ['bulan' => '2026-10']));

    $response->assertOk()->assertDownload('rekap-mengajar-u3-smp-2026-10.xlsx');

    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Rekap', 'Rincian'])
        // Baris kosong pemisah tidak terbaca: judul tabel di indeks 2.
        ->and($sheets['Rekap'][2])->toBe(['Guru', 'Terjadwal', 'Mengajar', 'Digantikan', 'Kosong', 'Belum dicatat', 'Menggantikan', '% Mengajar'])
        ->and($sheets['Rekap'][5][0])->toBe('Guru MA & MDA')
        ->and($sheets['Rekap'][6][0])->toBe('Jumlah')
        ->and($sheets['Rincian'])->toHaveCount(9)
        ->and($sheets['Rincian'][0])->toBe(['Tanggal', 'Jam', 'Kelas', 'Mapel', 'Pengampu', 'Status', 'Pengganti', 'Alasan', 'Dicatat oleh']);
});

test('hanya pemilik izin kelola presensi mengajar: guru 403, lembaga di luar cakupan 404', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('academic.teaching.recap'))->assertForbidden();
    $this->actingAs($this->guru)->get(route('academic.teaching.recap.export'))->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->operator)
        ->get(route('academic.teaching.recap', ['lembaga' => CoreFixtures::node($this->tenant, 'U1-MA')->id]))
        ->assertNotFound();
});
