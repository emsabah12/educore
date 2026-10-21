<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\ManagePlacements;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Jadwal pelajaran mingguan (AC-3c, PRD-002 §16): jam bebas per baris, bentrok kelas & bentrok guru.
 *
 * Data uji (tahun berjalan):
 *   SMP Unit 3 — VII-A: Matematika (Ani), Fiqih (tanpa pengampu); VII-B: IPA (tanpa pengampu)
 *   MDA Unit 3 — Ula 1: Nahwu (Ani; Ani juga ditempatkan di MDA Unit 3)
 *   MTs Unit 1 — VII-MTs: Fiqih (tanpa pengampu)
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $node = fn (string $code): string => CoreFixtures::node($this->tenant, $code)->id;
    $this->smp = $node('U3-SMP');
    $this->mda = $node('U3-MDA');
    $this->mts = $node('U1-MTS');

    $this->ani = jdwEmployee($this->tenant, 'Ani Guru', 'U3-SMP');
    $position = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id');
    app(ManagePlacements::class)->add($this->tenant->id, $this->ani->id, $this->mda, $position, '2020-07-01', false);

    foreach ([['VII-A', 7, $this->smp], ['VII-B', 7, $this->smp], ['Ula 1', 1, $this->mda], ['VII-MTs', 7, $this->mts]] as [$name, $grade, $organization]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $this->current->id, 'grade' => $grade, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    foreach ([['VII-A', $this->smp, ['Matematika', 'Fiqih']], ['VII-B', $this->smp, ['IPA']], ['Ula 1', $this->mda, ['Nahwu']], ['VII-MTs', $this->mts, ['Fiqih']]] as [$class, $organization, $names]) {
        $ids = [];

        foreach ($names as $name) {
            $existing = DB::table('ac_subjects')->where('organization_id', $organization)->where('name', $name)->value('id');

            if ($existing === null) {
                $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $organization, 'name' => $name])->assertSessionHasNoErrors();
                $existing = DB::table('ac_subjects')->where('organization_id', $organization)->where('name', $name)->value('id');
            }

            $ids[] = (string) $existing;
        }

        $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => jdwClass($class)->id]), ['subject_ids' => $ids])->assertSessionHasNoErrors();
    }

    foreach ([['VII-A', 'Matematika'], ['Ula 1', 'Nahwu']] as [$class, $subject]) {
        $this->actingAs($this->admin)
            ->patch(route('academic.class-subjects.update', ['classSubject' => jdwClassSubject($class, $subject)->id]), ['teacher_employee_id' => $this->ani->id])
            ->assertSessionHasNoErrors();
    }
});

function jdwEmployee(Tenant $tenant, string $name, string $organizationCode): Employee
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Female,
        birthPlace: null,
        birthDate: null,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($tenant, $organizationCode)->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));
}

function jdwClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function jdwClassSubject(string $class, string $subject): ClassSubject
{
    $classId = jdwClass($class)->id;
    $subjectId = DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('cs.class_id', $classId)->where('s.name', $subject)->value('cs.subject_id');

    return ClassSubject::query()->withoutGlobalScope('tenant')->where('class_id', $classId)->where('subject_id', $subjectId)->sole();
}

function jdwAdd(TestCase $test, mixed $user, string $class, string $subject, int $day, string $start, string $end): TestResponse
{
    return $test->actingAs($user)->post(route('academic.schedule.store', ['class' => jdwClass($class)->id]), [
        'class_subject_id' => jdwClassSubject($class, $subject)->id, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end,
    ]);
}

function jdwEntry(string $class, int $day, string $start): ScheduleEntry
{
    return ScheduleEntry::query()->withoutGlobalScope('tenant')->where('class_id', jdwClass($class)->id)->where('day_of_week', $day)->where('start_time', $start)->sole();
}

// ── Tambah & bentrok ──────────────────────────────────────────────────────────

test('tambah jadwal: tampil di detail kelas urut hari & jam beserta pengampu; tercatat di audit', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Fiqih', 2, '07:00', '08:00')->assertSessionHasNoErrors();
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '7:00', '08:20:00')->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => jdwClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedule', 2)
            ->where('schedule.0.day', 1)
            ->where('schedule.0.start', '07:00')
            ->where('schedule.0.end', '08:20')
            ->where('schedule.0.subject', 'Matematika')
            ->where('schedule.0.teacher', 'Ani Guru')
            ->where('schedule.1.subject', 'Fiqih')
            ->where('schedule.1.teacher', null),
        );

    expect(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::SCHEDULE_ENTRY_ADDED)->where('changes->mapel', 'Matematika')->sole()->changes, true))
        ->toEqual(['kelas' => 'VII-A', 'mapel' => 'Matematika', 'hari' => 'Senin', 'jam' => '07:00–08:20']);
});

test('bentrok kelas ditolak; jam yang hanya bersambung tidak bentrok', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '07:00', '08:20')->assertSessionHasNoErrors();

    jdwAdd($this, $this->operator, 'VII-A', 'Fiqih', 1, '08:00', '09:00')
        ->assertSessionHasErrors(['start_time' => 'Bentrok dengan jadwal kelas ini: Senin 07.00–08.20 Matematika.']);

    jdwAdd($this, $this->operator, 'VII-A', 'Fiqih', 1, '08:20', '09:00')->assertSessionHasNoErrors();

    // Kelas lain di jam yang sama tidak bentrok (pengampunya beda/kosong).
    jdwAdd($this, $this->operator, 'VII-B', 'IPA', 1, '07:00', '08:20')->assertSessionHasNoErrors();

    expect(DB::table('ac_schedule_entries')->count())->toBe(3);
});

test('bentrok guru lintas lembaga: Ani mengajar di SMP dan MDA pada jam yang beririsan ditolak', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '07:00', '08:20')->assertSessionHasNoErrors();

    jdwAdd($this, $this->admin, 'Ula 1', 'Nahwu', 1, '07:30', '08:00')
        ->assertSessionHasErrors(['start_time' => 'Bentrok jadwal guru Ani Guru: Senin 07.00–08.20 Matematika di kelas VII-A (SMP Unit 3).']);

    jdwAdd($this, $this->admin, 'Ula 1', 'Nahwu', 2, '07:30', '08:00')->assertSessionHasNoErrors();
});

test('ganti pengampu diperiksa terhadap jadwal guru baru', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '07:00', '08:20')->assertSessionHasNoErrors();
    jdwAdd($this, $this->operator, 'VII-B', 'IPA', 1, '08:00', '09:00')->assertSessionHasNoErrors();

    $ipa = jdwClassSubject('VII-B', 'IPA');

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $ipa->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasErrors(['teacher_employee_id' => 'Bentrok jadwal guru Ani Guru: Senin 07.00–08.20 Matematika di kelas VII-A (SMP Unit 3).']);

    expect($ipa->refresh()->teacher_employee_id)->toBeNull();

    // Jadwal IPA digeser → pengampu bisa diisi.
    $this->actingAs($this->operator)
        ->patch(route('academic.schedule.update', ['entry' => jdwEntry('VII-B', 1, '08:00')->id]), [
            'class_subject_id' => $ipa->id, 'day_of_week' => 1, 'start_time' => '08:20', 'end_time' => '09:00',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $ipa->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasNoErrors();
});

// ── Ubah, hapus, lepas mapel ──────────────────────────────────────────────────

test('ubah & hapus jadwal; isian salah ditolak; lepas mapel ikut menghapus jadwalnya', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '07:00', '08:20')->assertSessionHasNoErrors();
    jdwAdd($this, $this->operator, 'VII-A', 'Fiqih', 1, '08:20', '09:00')->assertSessionHasNoErrors();
    $entry = jdwEntry('VII-A', 1, '07:00');
    $math = jdwClassSubject('VII-A', 'Matematika');
    $url = route('academic.schedule.update', ['entry' => $entry->id]);

    $this->actingAs($this->operator)->patch($url, ['class_subject_id' => $math->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '07:00'])
        ->assertSessionHasErrors(['end_time' => 'Jam selesai harus sesudah jam mulai.']);
    $this->actingAs($this->operator)->patch($url, ['class_subject_id' => $math->id, 'day_of_week' => 1, 'start_time' => '25:00', 'end_time' => '26:00'])
        ->assertSessionHasErrors(['start_time' => 'Jam mulai wajib diisi, mis. 07:00.']);
    $this->actingAs($this->operator)->patch($url, ['class_subject_id' => jdwClassSubject('VII-B', 'IPA')->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:20'])
        ->assertSessionHasErrors(['class_subject_id' => 'Pilih mata pelajaran dari kelas ini.']);

    // Tanpa perubahan: tidak tercatat.
    $this->actingAs($this->operator)->patch($url, ['class_subject_id' => $math->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:20'])->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->patch($url, ['class_subject_id' => $math->id, 'day_of_week' => 3, 'start_time' => '07:00', 'end_time' => '08:20'])->assertSessionHasNoErrors();

    expect($entry->refresh()->day_of_week)->toBe(3)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::SCHEDULE_ENTRY_UPDATED)->count())->toBe(1);

    $this->actingAs($this->operator)->delete(route('academic.schedule.destroy', ['entry' => $entry->id]))->assertSessionHasNoErrors();
    expect(ScheduleEntry::query()->withoutGlobalScope('tenant')->whereKey($entry->id)->exists())->toBeFalse();

    // Lepas Fiqih → jadwal Fiqih ikut terhapus.
    $this->actingAs($this->operator)->delete(route('academic.class-subjects.destroy', ['classSubject' => jdwClassSubject('VII-A', 'Fiqih')->id]))->assertSessionHasNoErrors();

    expect(DB::table('ac_schedule_entries')->count())->toBe(0)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECT_REMOVED)->sole()->changes, true))
        ->toEqual(['kelas' => 'VII-A', 'mapel' => 'Fiqih', 'jadwal_dihapus' => 1]);
});

test('kelas tahun ajaran yang sudah lewat: jadwal hanya bisa dilihat', function () {
    jdwAdd($this, $this->operator, 'VII-A', 'Matematika', 1, '07:00', '08:20')->assertSessionHasNoErrors();
    $entry = jdwEntry('VII-A', 1, '07:00');

    $this->current->forceFill(['is_current' => false, 'end_date' => BusinessClock::today()->subDay()->toDateString()])->save();

    $past = 'Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat, tidak bisa diubah.';

    jdwAdd($this, $this->operator, 'VII-A', 'Fiqih', 2, '07:00', '08:00')->assertSessionHasErrors(['form' => $past]);
    $this->actingAs($this->operator)->delete(route('academic.schedule.destroy', ['entry' => $entry->id]))->assertSessionHasErrors(['form' => $past]);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => jdwClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('schedule', 1)->where('canManage', false));
});

test('hak akses: kelas di luar cakupan 404, tanpa izin kelola 403', function () {
    jdwAdd($this, $this->admin, 'VII-MTs', 'Fiqih', 1, '07:00', '08:00')->assertSessionHasNoErrors();
    $entry = jdwEntry('VII-MTs', 1, '07:00');
    $kepala = DevAccounts::user('kepala.unit1');

    jdwAdd($this, $this->operator, 'VII-MTs', 'Fiqih', 2, '07:00', '08:00')->assertNotFound();
    jdwAdd($this, $kepala, 'VII-MTs', 'Fiqih', 2, '07:00', '08:00')->assertForbidden();

    $this->actingAs($this->operator)->delete(route('academic.schedule.destroy', ['entry' => $entry->id]))->assertNotFound();
    $this->actingAs($kepala)->delete(route('academic.schedule.destroy', ['entry' => $entry->id]))->assertForbidden();
    $this->actingAs($this->operator)->delete(route('academic.schedule.destroy', ['entry' => (string) Str::uuid7()]))->assertNotFound();

    expect(DB::table('ac_schedule_entries')->count())->toBe(1);
});
