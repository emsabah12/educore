<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
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
use Tests\TestCase;

/*
 * Presensi per pertemuan & mode presensi (AC-4c, PRD-002 §17).
 *
 * MA Unit 1 memakai mode PER_PERTEMUAN. Kelas X-A (tanpa wali kelas) berisi Ahmad & Aisyah, dengan
 * Fiqih (pengampu: akun `guru`) dan Matematika (tanpa pengampu), keduanya dijadwalkan di hari yang
 * sama dengan hari ini: Fiqih 07.00–08.00, Matematika 08.00–09.00.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->today = BusinessClock::todayString();
    $this->day = BusinessClock::today()->dayOfWeekIso;

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA & MDA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma, 'academic_year_id' => $this->current->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
            'class_id' => prtClass()->id,
        ])->assertSessionHasNoErrors();
    }

    $ids = [];

    foreach (['Fiqih', 'Matematika'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name])->assertSessionHasNoErrors();
        $ids[] = (string) DB::table('ac_subjects')->where('organization_id', $this->ma)->where('name', $name)->value('id');
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => prtClass()->id]), ['subject_ids' => $ids])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->patch(route('academic.class-subjects.update', ['classSubject' => prtClassSubject('Fiqih')]), ['teacher_employee_id' => $this->employee->id])
        ->assertSessionHasNoErrors();

    foreach ([['Fiqih', '07:00', '08:00'], ['Matematika', '08:00', '09:00']] as [$subject, $start, $end]) {
        $this->actingAs($this->admin)->post(route('academic.schedule.store', ['class' => prtClass()->id]), [
            'class_subject_id' => prtClassSubject($subject), 'day_of_week' => $this->day, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.attendance-mode.assign'), [
        'target' => 'lembaga', 'organization_id' => $this->ma, 'mode' => 'PER_PERTEMUAN',
    ])->assertSessionHasNoErrors();

    $this->ahmad = prtEnrollment('Ahmad Fauzi');
    $this->aisyah = prtEnrollment('Aisyah Putri');
});

function prtClass(): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-A')->sole();
}

function prtClassSubject(string $subject): string
{
    return (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
}

function prtEntry(string $subject): string
{
    return (string) DB::table('ac_schedule_entries')->where('class_subject_id', prtClassSubject($subject))->value('id');
}

function prtEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

function prtAsGuru(TestCase $test, mixed $guru): void
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $guru, 'U1-MA');
}

/**
 * @return array<string, mixed>
 */
function prtIsian(string $date, string $ahmad, string $aisyah, string $ahmadStatus = 'H'): array
{
    return ['date' => $date, 'attendance' => [$ahmad => ['status' => $ahmadStatus], $aisyah => ['status' => 'H']]];
}

// ── Mode presensi ─────────────────────────────────────────────────────────────

test('mode presensi diatur Admin Yayasan di Kalender akademik; lembaga per pertemuan menutup presensi harian', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendanceModes.rules.0.mode', 'Per pertemuan')
            ->where('attendanceModes.rules.0.enforced', false)
            ->where('attendanceModes.usage', fn ($usage) => collect($usage)->contains(fn (array $row): bool => $row['id'] === $this->ma && $row['mode'] === 'Per pertemuan' && $row['source'] === 'Diatur')),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.attendance.show', ['class' => prtClass()->id]))
        ->assertInertia(fn (Assert $page) => $page->where('blocked', 'Lembaga ini memakai presensi per pertemuan: presensi diisi guru pengampu dari jadwal pelajaran.')->where('sheet', null));
    $this->actingAs($this->admin)
        ->post(route('academic.attendance.store', ['class' => prtClass()->id]), prtIsian($this->today, $this->ahmad, $this->aisyah))
        ->assertSessionHasErrors(['form' => 'Lembaga ini memakai presensi per pertemuan: presensi diisi guru pengampu dari jadwal pelajaran.']);
    $this->actingAs($this->admin)
        ->get(route('academic.classes.show', ['class' => prtClass()->id]))
        ->assertInertia(fn (Assert $page) => $page->where('lessonAttendance', true));

    // Selain Admin Yayasan tidak boleh mengatur mode.
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->post(route('academic.attendance-mode.assign'), ['target' => 'lembaga', 'organization_id' => $this->ma, 'mode' => 'HARIAN'])
        ->assertForbidden();
});

// ── Isi per pertemuan ─────────────────────────────────────────────────────────

test('guru pengampu mengisi presensi pertemuan dari Kelas saya; sesi menyimpan mapel & jam; tercatat di audit', function () {
    prtAsGuru($this, $this->guru);

    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('classes.0.lesson_mode', true)->where('schedule.0.entry_id', prtEntry('Fiqih')));

    $this->actingAs($this->guru)
        ->get(route('academic.my-lesson-attendance.show', ['entry' => prtEntry('Fiqih')]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/attendance/sheet')
            ->where('blocked', null)
            ->where('lesson.subject', 'Fiqih')
            ->where('lesson.start', '07:00')
            ->where('date', $this->today)
            ->has('sheet.students', 2),
        );

    $this->actingAs($this->guru)
        ->post(route('academic.my-lesson-attendance.store', ['entry' => prtEntry('Fiqih')]), prtIsian($this->today, $this->ahmad, $this->aisyah, 'A'))
        ->assertSessionHasNoErrors();

    $session = DB::table('ac_attendance_sessions')->sole();

    expect($session->class_subject_id)->toBe(prtClassSubject('Fiqih'))
        ->and(substr((string) $session->start_time, 0, 5))->toBe('07:00')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::ATTENDANCE_RECORDED)->sole()->changes, true))
        ->toEqual([
            'kelas' => 'X-A', 'tanggal' => $this->today, 'hadir' => 1, 'sakit' => 0, 'izin' => 0, 'alpa' => 1,
            'koreksi' => false, 'mapel' => 'Fiqih', 'jam' => '07:00–08:00',
        ]);
});

test('tanggal pertemuan: wajib hari jadwalnya; pengampu 7 hari terakhir; operator sepanjang tahun ajaran', function () {
    $lastWeek = BusinessClock::today()->subDays(7)->toDateString();
    $yesterday = BusinessClock::today()->subDay()->toDateString();

    prtAsGuru($this, $this->guru);
    $mine = route('academic.my-lesson-attendance.store', ['entry' => prtEntry('Fiqih')]);

    $this->actingAs($this->guru)->post($mine, prtIsian($yesterday, $this->ahmad, $this->aisyah))
        ->assertSessionHasErrors(['date']);
    $this->actingAs($this->guru)->post($mine, prtIsian($lastWeek, $this->ahmad, $this->aisyah))
        ->assertSessionHasErrors(['date' => 'Guru pengampu hanya bisa mengisi atau mengoreksi presensi 7 hari terakhir. Minta Operator Akademik untuk tanggal yang lebih lama.']);

    $this->flushSession();
    $this->actingAs($this->admin)
        ->post(route('academic.lesson-attendance.store', ['entry' => prtEntry('Fiqih')]), prtIsian($lastWeek, $this->ahmad, $this->aisyah))
        ->assertSessionHasNoErrors();

    expect(DB::table('ac_attendance_sessions')->count())->toBe(1);
});

test('hak akses: mapel tanpa pengampu hanya operator; bukan pengampu 404; di luar cakupan 404; tanpa izin kelola 403', function () {
    prtAsGuru($this, $this->guru);

    $this->actingAs($this->guru)->get(route('academic.my-lesson-attendance.show', ['entry' => prtEntry('Matematika')]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.lesson-attendance.show', ['entry' => prtEntry('Fiqih')]))->assertForbidden();
    // Bukan wali kelas X-A: presensi harian lewat Kelas saya tertutup.
    $this->actingAs($this->guru)->get(route('academic.my-attendance.show', ['class' => prtClass()->id]))->assertNotFound();

    $this->flushSession();
    $this->actingAs($this->admin)
        ->post(route('academic.lesson-attendance.store', ['entry' => prtEntry('Matematika')]), prtIsian($this->today, $this->ahmad, $this->aisyah))
        ->assertSessionHasNoErrors();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->get(route('academic.lesson-attendance.show', ['entry' => prtEntry('Fiqih')]))
        ->assertNotFound();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.lesson-attendance.store', ['entry' => prtEntry('Fiqih')]), prtIsian($this->today, $this->ahmad, $this->aisyah))
        ->assertForbidden();
});

// ── Riwayat & rekap ───────────────────────────────────────────────────────────

test('riwayat aman: jadwal diubah/dihapus sesi tetap; mapel yang sudah punya presensi tidak bisa dilepas; rekap per pertemuan per mapel', function () {
    $url = fn (string $subject) => route('academic.lesson-attendance.store', ['entry' => prtEntry($subject)]);
    $this->actingAs($this->admin)->post($url('Fiqih'), prtIsian($this->today, $this->ahmad, $this->aisyah, 'S'))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post($url('Matematika'), prtIsian($this->today, $this->ahmad, $this->aisyah))->assertSessionHasNoErrors();

    $fiqihEntry = prtEntry('Fiqih');
    $this->actingAs($this->admin)->patch(route('academic.schedule.update', ['entry' => $fiqihEntry]), [
        'class_subject_id' => prtClassSubject('Fiqih'), 'day_of_week' => $this->day, 'start_time' => '10:00', 'end_time' => '11:00',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('academic.schedule.destroy', ['entry' => $fiqihEntry]))->assertSessionHasNoErrors();

    $session = DB::table('ac_attendance_sessions')->where('class_subject_id', prtClassSubject('Fiqih'))->sole();

    expect($session->schedule_entry_id)->toBeNull()
        ->and(substr((string) $session->start_time, 0, 5))->toBe('07:00');

    $this->actingAs($this->admin)
        ->delete(route('academic.class-subjects.destroy', ['classSubject' => prtClassSubject('Fiqih')]))
        ->assertSessionHasErrors(['form' => 'Mata pelajaran Fiqih sudah punya presensi, jadi tidak bisa dilepas dari kelas. Hapus jadwalnya saja bila tidak dipakai lagi.']);

    // Rekap: bawaan per pertemuan (mode lembaga); semua mapel lalu hanya Fiqih.
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => prtClass()->id, 'periode' => 'tahun']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kind', 'pertemuan')
            ->where('recap.days', 2)
            ->where('recap.students.0.H', 1)
            ->where('recap.students.0.S', 1)
            ->where('recap.students.0.percent', 50),
        );
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => prtClass()->id, 'periode' => 'tahun', 'mapel' => prtClassSubject('Fiqih')]))
        ->assertInertia(fn (Assert $page) => $page->where('recap.days', 1)->where('recap.students.0.S', 1)->where('recap.students.0.percent', 0));
});

test('pengampu (bukan wali kelas) melihat rekap pertemuan mapelnya saja; lembaga harian menutup presensi per pertemuan', function () {
    $this->actingAs($this->admin)->post(route('academic.lesson-attendance.store', ['entry' => prtEntry('Matematika')]), prtIsian($this->today, $this->ahmad, $this->aisyah, 'A'))->assertSessionHasNoErrors();

    prtAsGuru($this, $this->guru);

    $this->actingAs($this->guru)
        ->get(route('academic.my-attendance.recap', ['class' => prtClass()->id, 'periode' => 'tahun']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kinds', [['value' => 'pertemuan', 'label' => 'Per pertemuan']])
            ->where('subjects', [['value' => prtClassSubject('Fiqih'), 'label' => 'Fiqih']])
            ->where('recap.days', 0),
        );

    // Kembali ke mode harian: presensi per pertemuan ditutup.
    $this->flushSession();
    $this->actingAs($this->admin)->post(route('academic.attendance-mode.assign'), [
        'target' => 'lembaga', 'organization_id' => $this->ma, 'mode' => 'HARIAN',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('academic.lesson-attendance.store', ['entry' => prtEntry('Fiqih')]), prtIsian($this->today, $this->ahmad, $this->aisyah))
        ->assertSessionHasErrors(['form' => 'Lembaga ini memakai presensi harian: presensi diisi wali kelas sekali sehari.']);
});
