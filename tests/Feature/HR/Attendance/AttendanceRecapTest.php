<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveType;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekap presensi pegawai bulanan (PRD-001 §14.4, HR-4c).
 *
 * "Sekarang" Kamis 22 Okt 2026 15.00; libur mingguan Ahad (4, 11, 18 Okt) → 19 hari wajib 1–22 Okt
 * (jam kerja 07.00–14.00). Penempatan utama MA Unit 1:
 *   Guru (akun `guru`, "Guru MA & MDA"): 1 Okt Hadir terlambat 20 mnt; 2 Okt Sakit; 4 Okt (Ahad)
 *     Hadir; 5 Okt Hadir pulang cepat 30 mnt; cuti 6–7 Okt.
 *   Citra: belum ada catatan.
 *   Eka: mulai 15 Okt (7 hari wajib).
 */

function rpgEmployee(mixed $test, string $name, string $start, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: $start, organizationId: $test->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function rpgRecord(mixed $test, string $date, string $status, ?string $in = null, ?string $out = null, int $late = 0, int $early = 0): void
{
    DB::table('hr_attendances')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'employee_id' => $test->guruId, 'organization_id' => $test->ma,
        'date' => $date, 'status' => $status, 'check_in' => $in, 'check_in_source' => $in === null ? null : 'PETUGAS',
        'check_out' => $out, 'check_out_source' => $out === null ? null : 'PETUGAS', 'late_minutes' => $late, 'early_minutes' => $early,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return array<string, int>
 */
function rpgCounts(int $required, int $present = 0, int $late = 0, int $lateMinutes = 0, int $early = 0, int $earlyMinutes = 0, int $sick = 0, int $leave = 0, int $empty = 0): array
{
    return [
        'required' => $required, 'present' => $present, 'late' => $late, 'late_minutes' => $lateMinutes, 'early' => $early,
        'early_minutes' => $earlyMinutes, 'sick' => $sick, 'permit' => 0, 'duty' => 0, 'leave' => $leave, 'absent' => 0, 'empty' => $empty,
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->kepala = DevAccounts::user('kepala.unit1');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $this->guruId = rpgEmployee($this, 'Guru', '2020-07-01', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    rpgEmployee($this, 'Citra', '2020-07-01');
    rpgEmployee($this, 'Eka', '2026-10-15');

    rpgRecord($this, '2026-10-01', 'HADIR', '07:20:00', '14:00:00', late: 20);
    rpgRecord($this, '2026-10-02', 'SAKIT');
    rpgRecord($this, '2026-10-04', 'HADIR', '08:00:00');
    rpgRecord($this, '2026-10-05', 'HADIR', '07:00:00', '13:30:00', early: 30);

    $annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();
    $this->actingAs($this->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $this->guruId, 'leave_type_id' => $annual->id, 'start_date' => '2026-10-06', 'end_date' => '2026-10-07', 'reason' => 'Keperluan keluarga',
    ])->assertSessionHasNoErrors();
    $this->flushSession();
});

test('rekap lembaga per pegawai: hari wajib, hadir, terlambat & pulang cepat, sakit, cuti, belum diisi; penempatan baru dihitung sejak mulai', function () {
    $this->actingAs($this->kepala)
        ->get(route('hr.attendance.recap', ['lembaga' => $this->ma, 'bulan' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/attendance/recap')
            ->where('label', 'Oktober 2026')
            ->where('until', '2026-10-22')
            ->has('rows', 3)
            ->where('rows.0.name', 'Citra')
            ->where('rows.0', fn ($row) => array_diff_key(collect($row)->all(), array_flip(['employee_id', 'name', 'employee_number'])) === rpgCounts(19, empty: 19))
            ->where('rows.1.name', 'Eka')
            ->where('rows.1.required', 7)
            ->where('rows.2.name', 'Guru MA & MDA')
            // Hadir 4 Okt (Ahad) ikut dihitung, tetapi bukan hari wajib.
            ->where('rows.2', fn ($row) => array_diff_key(collect($row)->all(), array_flip(['employee_id', 'name', 'employee_number'])) === rpgCounts(19, 3, 1, 20, 1, 30, 1, 2, 14))
            ->where('totals.required', 45));
});

test('unduh .xlsx: lembar Rekap dan Rincian per pegawai per hari', function () {
    $response = $this->actingAs($this->kepala)->get(route('hr.attendance.recap.export', ['lembaga' => $this->ma, 'bulan' => '2026-10']));

    $response->assertOk()->assertDownload('rekap-presensi-pegawai-u1-ma-2026-10.xlsx');

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

    // Guru 19 hari wajib + 1 catatan hari Ahad; Citra 19; Eka 7; + judul.
    expect(array_keys($sheets))->toBe(['Rekap', 'Rincian'])
        ->and($sheets['Rekap'][1][0])->toBe('Nama')
        ->and(end($sheets['Rekap'])[0])->toBe('Jumlah')
        ->and($sheets['Rincian'])->toHaveCount(47)
        ->and($sheets['Rincian'][1])->toBe(['2026-10-01', 'Citra', '07.00–14.00 (jam kerja)', 'Belum diisi', '', '', 0, 0]);
});

test('ringkasan bulan berjalan di Presensi saya dan di detail pegawai (bagi yang boleh melihat data pribadi)', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)
        ->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.label', 'Oktober 2026')
            ->where('summary.counts.present', 3)
            ->where('summary.counts.leave', 2)
            ->where('summary.counts.empty', 14));

    $this->flushSession();
    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page->where('attendance.counts.late_minutes', 20));
});

test('rekap hanya untuk pemilik izin kelola presensi pegawai', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.attendance.recap'))->assertForbidden();
    $this->actingAs($this->guru)->get(route('hr.attendance.recap.export'))->assertForbidden();
});
