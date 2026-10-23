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
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Presensi saya — absen mandiri pegawai (PRD-001 §14.2, HR-4a).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai dengan penempatan utama MA Unit 1. Jam kerja
 * bawaan 07:00–14:00, toleransi 15 menit; libur mingguan Ahad.
 */

function absAt(mixed $test, string $moment): void
{
    $test->travelTo(CarbonImmutable::parse($moment, BusinessClock::timezone()));
}

function absGuru(mixed $test): void
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $test->guru, 'U1-MA');
}

beforeEach(function () {
    absAt($this, '2026-10-22 06:00:00');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');
    $this->admin = DevAccounts::user('admin.yayasan');

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id,
        name: 'Guru', gender: Gender::Female, birthPlace: null, birthDate: null, phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
});

test('staf: jam wajib dari jam kerja; absen masuk terlambat & pulang cepat dihitung; sekali saja; riwayat bulan ini; tercatat di audit', function () {
    absAt($this, '2026-10-22 07:20:40');
    absGuru($this);

    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/my-attendance')
            ->where('linked', true)
            ->where('today.date', '2026-10-22')
            ->where('today.required', true)
            ->where('today.expected', '07.00–14.00 (jam kerja)')
            ->where('today.status', 'BELUM')
            ->where('canCheckIn', true)
            ->where('canCheckOut', false),
        );

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-out'))
        ->assertSessionHasErrors(['form' => 'Absen masuk dulu sebelum absen pulang.']);

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.my-attendance.index'));

    $row = DB::table('hr_attendances')->where('employee_id', $this->employee)->sole();
    expect([$row->status, substr((string) $row->check_in, 0, 8), $row->check_in_source, substr((string) $row->expected_start, 0, 5), (int) $row->late_minutes])
        ->toBe(['HADIR', '07:20:00', 'MANDIRI', '07:00', 20])
        ->and($row->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U1-MA')->id);

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))
        ->assertSessionHasErrors(['form' => 'Anda sudah absen masuk hari ini pukul 07.20.']);

    absAt($this, '2026-10-22 13:30:00');
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-out'))->assertSessionHasNoErrors();
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-out'))
        ->assertSessionHasErrors(['form' => 'Anda sudah absen pulang hari ini pukul 13.30.']);

    expect((int) DB::table('hr_attendances')->where('employee_id', $this->employee)->value('early_minutes'))->toBe(30);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_CHECKED_IN)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['tanggal' => '2026-10-22', 'jam' => '07:20', 'terlambat_menit' => 20])
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_CHECKED_OUT)->count())->toBe(1);

    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.status', 'HADIR')
            ->where('today.check_in', '07.20')
            ->where('today.check_out', '13.30')
            ->where('today.late', 20)
            ->where('today.early', 30)
            ->where('canCheckIn', false)
            ->where('canCheckOut', false)
            ->has('days', 22)
            // Rabu 21 Okt: hari kerja yang belum diisi; Ahad 18 Okt: libur.
            ->where('days.1.date', '2026-10-21')
            ->where('days.1.status', 'BELUM')
            ->where('days.1.status_label', 'Belum diisi')
            ->where('days.4.date', '2026-10-18')
            ->where('days.4.required', false)
            ->where('days.4.status', null),
        );
});

test('jam kerja per jenjang; hari libur tetap boleh absen tanpa terlambat; cuti disetujui & isian petugas menolak absen; akun tanpa data pegawai', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.work-hours.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'start' => '14:00', 'end' => '13:00', 'tolerance' => 5])
        ->assertSessionHasErrors(['end' => 'Nilai aturan tidak valid: jam pulang harus sesudah jam masuk.']);
    $this->actingAs($this->admin)
        ->post(route('hr.work-hours.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'start' => '06:30', 'end' => '12:00', 'tolerance' => 0])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get(route('hr.leave-settings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('workHours.rules.0.target', 'Semua MA')->where('workHours.rules.0.days', '06.30–12.00, toleransi 0 menit'));

    absAt($this, '2026-10-22 06:31:00');
    absGuru($this);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))->assertSessionHasNoErrors();
    expect((int) DB::table('hr_attendances')->where('date', '2026-10-22')->value('late_minutes'))->toBe(1);

    // Ahad: tidak wajib, tetap boleh absen, tanpa hitungan terlambat.
    absAt($this, '2026-10-25 10:00:00');
    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('today.required', false)->where('today.expected', 'Libur')->where('canCheckIn', true));
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))->assertSessionHasNoErrors();
    expect(DB::table('hr_attendances')->where('date', '2026-10-25')->first(['expected_start', 'late_minutes']))
        ->toEqual((object) ['expected_start' => null, 'late_minutes' => 0]);

    // Senin: sudah diisi petugas "Sakit".
    absAt($this, '2026-10-26 07:00:00');
    DB::table('hr_attendances')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee,
        'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'date' => '2026-10-26', 'status' => 'SAKIT',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))
        ->assertSessionHasErrors(['form' => 'Presensi hari ini sudah diisi petugas: Sakit. Hubungi petugas presensi bila keliru.']);

    // Selasa: cuti disetujui.
    absAt($this, '2026-10-27 07:00:00');
    $annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();
    $this->flushSession();
    $this->actingAs($this->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $this->employee, 'leave_type_id' => $annual->id, 'start_date' => '2026-10-27', 'end_date' => '2026-10-27', 'reason' => 'Keperluan keluarga',
    ])->assertSessionHasNoErrors();

    absGuru($this);
    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('today.status', 'CUTI')->where('canCheckIn', false));
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))
        ->assertSessionHasErrors(['form' => 'Hari ini Anda tercatat cuti yang sudah disetujui, jadi tidak perlu absen.']);

    // Akun tanpa data pegawai.
    $this->flushSession();
    $this->actingAs($this->admin)->get(route('hr.my-attendance.index'))->assertInertia(fn (Assert $page) => $page->where('linked', false));
    $this->actingAs($this->admin)->post(route('hr.my-attendance.check-in'))->assertNotFound();
});

test('guru: jam wajib dari jadwal mengajar (pelajaran pertama s.d. terakhir); hari tanpa pelajaran tidak wajib', function () {
    $current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $ma, 'academic_year_id' => $current->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $ma, 'name' => 'Fiqih'])->assertSessionHasNoErrors();

    $classId = (string) DB::table('ac_classes')->where('name', 'X-A')->value('id');
    $subjectId = (string) DB::table('ac_subjects')->where('organization_id', $ma)->where('name', 'Fiqih')->value('id');
    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $classId]), ['subject_ids' => [$subjectId]])->assertSessionHasNoErrors();
    $classSubjectId = (string) DB::table('ac_class_subjects')->where('class_id', $classId)->value('id');
    $this->actingAs($this->admin)->patch(route('academic.class-subjects.update', ['classSubject' => $classSubjectId]), ['teacher_employee_id' => $this->employee])->assertSessionHasNoErrors();

    // Kamis 09:00–10:30 & 12:00–13:00; Senin 07:30–09:00.
    foreach ([[4, '09:00', '10:30'], [4, '12:00', '13:00'], [1, '07:30', '09:00']] as [$day, $start, $end]) {
        $this->actingAs($this->admin)->post(route('academic.schedule.store', ['class' => $classId]), [
            'class_subject_id' => $classSubjectId, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    absAt($this, '2026-10-22 09:20:00');
    absGuru($this);
    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.required', true)
            ->where('today.expected', '09.00–13.00 (jadwal mengajar)')
            // Senin 19 Okt (3 hari sebelumnya): 07.30–09.00; Jumat 16 Okt: tanpa pelajaran.
            ->where('days.3.date', '2026-10-19')
            ->where('days.3.expected', '07.30–09.00 (jadwal mengajar)')
            ->where('days.6.date', '2026-10-16')
            ->where('days.6.required', false)
            ->where('days.6.expected', 'Tidak ada jadwal mengajar'),
        );

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))->assertSessionHasNoErrors();
    $row = DB::table('hr_attendances')->where('employee_id', $this->employee)->sole();
    expect([substr((string) $row->expected_start, 0, 5), substr((string) $row->expected_end, 0, 5), (int) $row->late_minutes])->toBe(['09:00', '13:00', 20]);
});
