<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Teaching\SubstituteTasks;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Tugas guru pengganti di Kelas saya & presensi siswa oleh pengganti; pencatatan otomatis dari
 * presensi per pertemuan (PRD-002 §20.3, AC-6b-2).
 *
 * "Sekarang" Kamis 22 Okt 2026 13.00. MA Unit 1 memakai presensi per pertemuan. Kelas X-A berisi
 * Ahmad & Aisyah; Kamis: Fiqih 07.00–08.00 (akun `guru`), Matematika 08.00–09.00 (Budi, tanpa akun).
 */

function sbtEmployee(mixed $test, string $name, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $test->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function sbtClassSubject(string $subject): string
{
    return (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
}

function sbtEntry(string $subject): string
{
    return (string) DB::table('ac_schedule_entries')->where('class_subject_id', sbtClassSubject($subject))->value('id');
}

function sbtEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

/**
 * @return array<string, mixed>
 */
function sbtIsian(mixed $test, string $date): array
{
    return [
        'date' => $date,
        'attendance' => [
            $test->ahmad => ['status' => 'H', 'note' => ''],
            $test->aisyah => ['status' => 'S', 'note' => 'Demam'],
        ],
    ];
}

function sbtTeaching(string $subject, string $date): ?stdClass
{
    $row = DB::table('ac_teaching_records')->where('schedule_entry_id', sbtEntry($subject))->where('date', $date)->first();

    return $row instanceof stdClass ? $row : null;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $this->guruId = sbtEmployee($this, 'Guru MA', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->budi = sbtEmployee($this, 'Budi Matematika');

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma, 'academic_year_id' => $year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $classId = (string) DB::table('ac_classes')->where('name', 'X-A')->value('id');

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2025-07-14', 'entry_type' => 'BARU', 'class_id' => $classId,
        ])->assertSessionHasNoErrors();
    }

    $subjectIds = [];

    foreach (['Fiqih', 'Matematika'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name])->assertSessionHasNoErrors();
        $subjectIds[] = (string) DB::table('ac_subjects')->where('organization_id', $this->ma)->where('name', $name)->value('id');
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $classId]), ['subject_ids' => $subjectIds])->assertSessionHasNoErrors();

    foreach ([['Fiqih', $this->guruId, '07:00', '08:00'], ['Matematika', $this->budi, '08:00', '09:00']] as [$subject, $teacher, $start, $end]) {
        $this->actingAs($this->admin)->patch(route('academic.class-subjects.update', ['classSubject' => sbtClassSubject($subject)]), ['teacher_employee_id' => $teacher])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('academic.schedule.store', ['class' => $classId]), [
            'class_subject_id' => sbtClassSubject($subject), 'day_of_week' => 4, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.attendance-mode.assign'), [
        'target' => 'lembaga', 'organization_id' => $this->ma, 'mode' => 'PER_PERTEMUAN',
    ])->assertSessionHasNoErrors();

    // Budi sakit hari ini dan cuti Kamis depan: Guru ditunjuk sebagai pengganti keduanya.
    foreach (['2026-10-22', '2026-10-29'] as $date) {
        $this->actingAs($this->admin)->post(route('academic.teaching.store', ['entry' => sbtEntry('Matematika')]), [
            'date' => $date, 'status' => 'DIGANTIKAN', 'substitute_employee_id' => $this->guruId, 'absence_reason' => 'SAKIT',
        ])->assertSessionHasNoErrors();
    }

    $this->ahmad = sbtEnrollment('Ahmad Fauzi');
    $this->aisyah = sbtEnrollment('Aisyah Putri');
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
});

test('Kelas saya menampilkan tugas menggantikan; hanya pertemuan hari-H s.d. 6 hari sesudahnya yang bisa diisi presensinya', function () {
    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('substitutions', 2)
            ->where('substitutions.0.date', '2026-10-22')
            ->where('substitutions.0.subject', 'Matematika')
            ->where('substitutions.0.teacher', 'Budi Matematika')
            ->where('substitutions.0.reason', 'Sakit')
            ->where('substitutions.0.can_fill', true)
            ->where('substitutions.1.date', '2026-10-29')
            ->where('substitutions.1.can_fill', false));

    // Pengganti tanpa kelas sendiri tetap punya tugas (menu Kelas saya muncul karenanya).
    expect(app(SubstituteTasks::class)->hasAny($this->tenant->id, $this->guruId))->toBeTrue()
        ->and(app(SubstituteTasks::class)->hasAny($this->tenant->id, $this->budi))->toBeFalse();
});

test('pengganti mengisi presensi siswa pertemuan yang ia gantikan; catatan "Digantikan" tetap', function () {
    $url = route('academic.substitute-attendance.show', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-22']);

    $this->actingAs($this->guru)->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/attendance/sheet')
            ->where('blocked', null)
            ->where('window', ['min' => '2026-10-22', 'max' => '2026-10-22'])
            ->where('asSubstitute', true)
            ->has('sheet.students', 2));

    $this->actingAs($this->guru)
        ->post(route('academic.substitute-attendance.store', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-22']), sbtIsian($this, '2026-10-22'))
        ->assertSessionHasNoErrors()
        ->assertRedirect($url.'?tanggal=2026-10-22');

    $session = DB::table('ac_attendance_sessions')->sole();
    $record = sbtTeaching('Matematika', '2026-10-22');

    expect($session->class_subject_id)->toBe(sbtClassSubject('Matematika'))
        ->and($record?->status)->toBe('DIGANTIKAN')
        ->and($record?->substitute_employee_id)->toBe($this->guruId);
});

test('pengganti tidak bisa mengisi tanggal lain, pertemuan yang akan datang, atau pertemuan yang tidak ia gantikan', function () {
    $store = route('academic.substitute-attendance.store', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-22']);

    // Tanggal di isian berbeda dari pertemuan yang digantikan.
    $this->actingAs($this->guru)->post($store, sbtIsian($this, '2026-10-15'))->assertSessionHasErrors(['date']);

    // Kamis depan: tampil terkunci, simpan ditolak.
    $this->actingAs($this->guru)
        ->get(route('academic.substitute-attendance.show', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-29']))
        ->assertInertia(fn (Assert $page) => $page->where('window', null)->where('blocked', 'Presensi pertemuan yang Anda gantikan bisa diisi pada hari-H sampai 6 hari sesudahnya.'));
    $this->actingAs($this->guru)
        ->post(route('academic.substitute-attendance.store', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-29']), sbtIsian($this, '2026-10-29'))
        ->assertSessionHasErrors(['date']);

    // Bukan pertemuan yang ia gantikan (minggu lalu, dan pelajaran Fiqih miliknya sendiri lewat pintu ini).
    $this->actingAs($this->guru)->get(route('academic.substitute-attendance.show', ['entry' => sbtEntry('Matematika'), 'date' => '2026-10-15']))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.substitute-attendance.show', ['entry' => sbtEntry('Fiqih'), 'date' => '2026-10-22']))->assertNotFound();

    expect(DB::table('ac_attendance_sessions')->count())->toBe(0);
});

test('pengampu mengisi presensi siswa per pertemuan → otomatis tercatat Mengajar', function () {
    $mine = route('academic.my-lesson-attendance.store', ['entry' => sbtEntry('Fiqih')]);

    $this->actingAs($this->guru)->post($mine, sbtIsian($this, '2026-10-22'))->assertSessionHasNoErrors();

    $record = sbtTeaching('Fiqih', '2026-10-22');

    expect($record?->status)->toBe('MENGAJAR')
        ->and($record?->source)->toBe('OTOMATIS')
        ->and($record?->teacher_employee_id)->toBe($this->guruId);

    // Waktu dibekukan: semua audit bercap waktu sama, jadi diperiksa seluruhnya.
    $audit = DB::table('audit_logs')->where('action', 'academic.teaching_recorded')->pluck('changes')->implode(' ');
    expect($audit)->toContain('otomatis dari presensi siswa');
});

test('catatan petugas tidak diubah oleh presensi siswa yang diisi pengampu kemudian', function () {
    $this->flushSession();
    $this->actingAs($this->admin)->post(route('academic.teaching.store', ['entry' => sbtEntry('Fiqih')]), [
        'date' => '2026-10-22', 'status' => 'KOSONG', 'absence_reason' => 'IZIN',
    ])->assertSessionHasNoErrors();
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->post(route('academic.my-lesson-attendance.store', ['entry' => sbtEntry('Fiqih')]), sbtIsian($this, '2026-10-22'))
        ->assertSessionHasNoErrors();

    expect(sbtTeaching('Fiqih', '2026-10-22')?->status)->toBe('KOSONG')
        ->and(sbtTeaching('Fiqih', '2026-10-22')?->source)->toBe('PETUGAS');
});
