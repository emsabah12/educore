<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\ManagePlacements;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrPayComponentSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Payroll\PayComponent;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Honor mengajar (jam mati & jam hidup) dan potongan presensi (PRD-001 §16.2, HR-5b, OD-HR-11).
 *
 * "Sekarang" Kamis 22 Okt 2026 13.00.
 * Aturan: se-yayasan jam mati, 45 mnt/JP, Rp 30.000/JP; SMP Unit 3 jam hidup, 45 mnt/JP, Rp 10.000/JP.
 * Potongan se-yayasan: Alpa Rp 50.000/hari, Terlambat Rp 10.000/kali.
 *
 * Hana (Honorer → menerima honor JP), penempatan utama MA Unit 1 + SMP Unit 3:
 *   MA  X-A  Fisika Senin 07.00–08.30 (90 mnt = 2 JP)  → jam mati 2 × 30.000 = 60.000.
 *   SMP VII-A Matematika Kamis 09.00–10.30 (2 JP): 1 Okt Mengajar, 8 Okt Kosong, 15 Okt Mengajar = 4 JP;
 *       menggantikan VII-B (60 mnt = 1 JP, tidak pas kelipatan) 8 Okt = 1 JP → jam hidup 5 × 10.000 = 50.000.
 *   Presensi: Alpa 5 Okt, Hadir terlambat 12 Okt → potongan 60.000; uang transport 15.000 × 1 hari.
 * Budi (Tetap → tidak menerima honor JP) mengampu VII-B.
 */

function hjpEmployee(mixed $test, string $name, string $organizationCode, string $employmentType): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Female, birthPlace: null, birthDate: null,
        phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, $employmentType), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, $organizationCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function hjpClass(mixed $test, string $organizationId, string $name, int $grade, string $subject, string $teacherId, int $day, string $start, string $end): void
{
    $test->actingAs($test->admin)->post(route('academic.classes.store'), [
        'organization_id' => $organizationId, 'academic_year_id' => $test->year->id, 'grade' => $grade, 'name' => $name,
    ])->assertSessionHasNoErrors();
    $test->actingAs($test->admin)->post(route('academic.subjects.store'), ['organization_id' => $organizationId, 'name' => $subject])->assertSessionHasNoErrors();

    $classId = (string) DB::table('ac_classes')->where('name', $name)->value('id');
    $subjectId = (string) DB::table('ac_subjects')->where('organization_id', $organizationId)->where('name', $subject)->value('id');
    $test->actingAs($test->admin)->post(route('academic.class-subjects.store', ['class' => $classId]), ['subject_ids' => [$subjectId]])->assertSessionHasNoErrors();
    $classSubjectId = (string) DB::table('ac_class_subjects')->where('class_id', $classId)->value('id');
    $test->actingAs($test->admin)->patch(route('academic.class-subjects.update', ['classSubject' => $classSubjectId]), ['teacher_employee_id' => $teacherId])->assertSessionHasNoErrors();
    $test->actingAs($test->admin)->post(route('academic.schedule.store', ['class' => $classId]), [
        'class_subject_id' => $classSubjectId, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end,
    ])->assertSessionHasNoErrors();
}

/**
 * @param  array<string, string>  $data
 */
function hjpRecord(mixed $test, string $className, string $date, array $data): void
{
    $entry = (string) DB::table('ac_schedule_entries as e')->join('ac_classes as c', 'c.id', '=', 'e.class_id')->where('c.name', $className)->value('e.id');

    $test->actingAs($test->operator)
        ->post(route('academic.teaching.store', ['entry' => $entry]), ['date' => $date] + $data)
        ->assertSessionHasNoErrors();
}

function hjpAttendance(mixed $test, string $date, string $status, ?string $in = null, int $late = 0): void
{
    DB::table('hr_attendances')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'employee_id' => $test->hana, 'organization_id' => $test->ma,
        'date' => $date, 'status' => $status, 'check_in' => $in, 'check_in_source' => $in === null ? null : 'PETUGAS',
        'late_minutes' => $late, 'early_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrPayComponentSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;

    $this->hana = hjpEmployee($this, 'Hana', 'U1-MA', 'HONORER');
    $position = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id');
    app(ManagePlacements::class)->add($this->tenant->id, $this->hana, $this->smp, $position, '2020-07-01', false);
    $this->budi = hjpEmployee($this, 'Budi', 'U3-SMP', 'TETAP');

    hjpClass($this, $this->ma, 'X-A', 10, 'Fisika', $this->hana, 1, '07:00', '08:30');
    hjpClass($this, $this->smp, 'VII-A', 7, 'Matematika', $this->hana, 4, '09:00', '10:30');
    hjpClass($this, $this->smp, 'VII-B', 7, 'IPA', $this->budi, 4, '11:00', '12:00');
    $this->flushSession();

    hjpRecord($this, 'VII-A', '2026-10-01', ['status' => 'MENGAJAR']);
    hjpRecord($this, 'VII-A', '2026-10-08', ['status' => 'KOSONG', 'absence_reason' => 'IZIN']);
    hjpRecord($this, 'VII-A', '2026-10-15', ['status' => 'MENGAJAR']);
    hjpRecord($this, 'VII-B', '2026-10-08', ['status' => 'DIGANTIKAN', 'absence_reason' => 'SAKIT', 'substitute_employee_id' => $this->hana]);
    $this->flushSession();

    hjpAttendance($this, '2026-10-05', 'ALPA');
    hjpAttendance($this, '2026-10-12', 'HADIR', '07:20:00', 20);

    $transport = PayComponent::query()->withoutGlobalScope('tenant')->where('code', 'TRANSPORT')->sole();
    app(EmployeePay::class)->save($this->tenant->id, $this->hana, [$transport->id => '15000'], null);
});

test('honor JP jam mati & jam hidup per lembaga, potongan presensi, perkiraan bulan di setup gaji pegawai', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'yayasan', 'mode' => 'JAM_MATI', 'minutes' => 45, 'rate' => '30.000'])->assertSessionHasNoErrors();
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'lembaga', 'organization_id' => $this->smp, 'mode' => 'JAM_HIDUP', 'minutes' => 45, 'rate' => '10.000'])->assertSessionHasNoErrors();
    $this->post(route('hr.payroll.deduction'), ['target' => 'yayasan', 'absent' => '50.000', 'late' => '10.000', 'early' => '0'])->assertSessionHasNoErrors();

    $this->get(route('hr.payroll-employees.show', ['employee' => $this->hana, 'bulan' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.label', 'Oktober 2026')
            ->where('preview.until', '2026-10-22')
            ->where('preview.teaching.eligible', true)
            ->where('preview.teaching.lines', fn ($lines) => collect($lines)->map(fn ($line) => [$line['organization'], $line['mode'], $line['sessions'], $line['jp'], $line['amount'], $line['irregular']])->sort()->values()->all() == [
                ['MA Unit 1', 'JAM_MATI', 1, 2, 60000, 0],
                ['SMP Unit 3', 'JAM_HIDUP', 3, 5, 50000, 1],
            ])
            ->where('preview.teaching.total', 110000)
            ->where('preview.attendance.absent', 1)
            ->where('preview.attendance.late', 1)
            ->where('preview.attendance.present_days', 1)
            ->where('preview.attendance.deduction', 60000)
            ->where('preview.present_day_pay', 15000)
            ->where('preview.earning', 125000)
            ->where('preview.deduction', 60000));

    // Budi mengajar tetapi jenis pegawainya (Tetap) tidak menerima honor JP → Rp 0.
    $this->get(route('hr.payroll-employees.show', ['employee' => $this->budi]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.teaching.eligible', false)
            ->where('preview.teaching.total', 0)
            ->has('preview.teaching.lines', 1));

    $this->get(route('hr.payroll-settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('teachingPay.rules', 2)
            ->has('deduction.rules', 1)
            ->has('targets.jenjang'));
});

test('penerima honor JP diatur di Katalog SDM (Honorer & GTT dicentang sejak awal)', function () {
    $this->actingAs($this->admin)
        ->get(route('hr.catalog.index', ['jenis' => 'jenis-pegawai']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flag.badge', 'Honor per JP')
            ->where('items', fn ($items) => collect($items)->where('flag', true)->pluck('code')->all() === ['HONORER']));

    $tetap = (string) CatalogKind::EmploymentType->query()->withoutGlobalScope('tenant')->where('code', 'TETAP')->value('id');
    $this->patch(route('hr.catalog.update', ['kind' => 'jenis-pegawai', 'item' => $tetap]), ['name' => 'Pegawai Tetap', 'flag' => '1'])->assertSessionHasNoErrors();
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'yayasan', 'mode' => 'JAM_MATI', 'minutes' => 45, 'rate' => '30000']);

    // Budi (Tetap) kini menerima honor: VII-B 60 mnt = 1 JP (SMP masih bawaan yayasan: jam mati).
    $this->get(route('hr.payroll-employees.show', ['employee' => $this->budi]))
        ->assertInertia(fn (Assert $page) => $page->where('preview.teaching.eligible', true)->where('preview.teaching.total', 30000));
});

test('aturan honor & potongan: nilai tidak sah ditolak; hanya pemilik izin gaji', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'yayasan', 'mode' => 'JAM_BEBAS', 'minutes' => 45, 'rate' => '1'])->assertSessionHasErrors('mode');
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'yayasan', 'mode' => 'JAM_MATI', 'minutes' => 5, 'rate' => '1'])->assertSessionHasErrors('minutes');
    $this->post(route('hr.payroll.teaching-pay'), ['target' => 'lembaga', 'mode' => 'JAM_MATI', 'minutes' => 45, 'rate' => '1'])->assertSessionHasErrors(['organization_id' => 'Pilih lembaga atau unit.']);
    $this->post(route('hr.payroll.deduction'), ['target' => 'yayasan', 'absent' => '50.000,5', 'late' => '0', 'early' => '0'])->assertSessionHasErrors('absent');

    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('hr.payroll.teaching-pay'), ['target' => 'yayasan', 'mode' => 'JAM_MATI', 'minutes' => 45, 'rate' => '1'])
        ->assertForbidden();
});
