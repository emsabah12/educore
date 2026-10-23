<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
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
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Presensi mengajar & jadwal piket (PRD-002 §20, AC-6a).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai dengan penempatan utama MA Unit 1, juga
 * ditempatkan & mengajar di SMP Unit 3:
 *   SMP Unit 3 — VII-A Matematika: Kamis 09:00–10:00, Rabu 07:00–08:00
 *   MA Unit 1  — X-A Fiqih: Kamis 13:00–14:00
 * Budi = pegawai SMP Unit 3 (tanpa akun); Citra = pegawai MA Unit 1 (tanpa akun).
 */

function pmgAt(mixed $test, string $moment): void
{
    $test->travelTo(CarbonImmutable::parse($moment, BusinessClock::timezone()));
}

function pmgEmployee(mixed $test, string $name, string $organizationCode, ?User $user = null): string
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

/**
 * @param  list<array{0: int, 1: string, 2: string}>  $slots
 */
function pmgClass(mixed $test, string $organizationCode, string $name, int $grade, string $subject, string $teacherId, array $slots): string
{
    $organization = CoreFixtures::node($test->tenant, $organizationCode)->id;

    $test->actingAs($test->admin)->post(route('academic.classes.store'), [
        'organization_id' => $organization, 'academic_year_id' => $test->year->id, 'grade' => $grade, 'name' => $name,
    ])->assertSessionHasNoErrors();
    $test->actingAs($test->admin)->post(route('academic.subjects.store'), ['organization_id' => $organization, 'name' => $subject])->assertSessionHasNoErrors();

    $classId = (string) DB::table('ac_classes')->where('name', $name)->value('id');
    $subjectId = (string) DB::table('ac_subjects')->where('organization_id', $organization)->where('name', $subject)->value('id');
    $test->actingAs($test->admin)->post(route('academic.class-subjects.store', ['class' => $classId]), ['subject_ids' => [$subjectId]])->assertSessionHasNoErrors();
    $classSubjectId = (string) DB::table('ac_class_subjects')->where('class_id', $classId)->value('id');
    $test->actingAs($test->admin)->patch(route('academic.class-subjects.update', ['classSubject' => $classSubjectId]), ['teacher_employee_id' => $teacherId])->assertSessionHasNoErrors();

    foreach ($slots as [$day, $start, $end]) {
        $test->actingAs($test->admin)->post(route('academic.schedule.store', ['class' => $classId]), [
            'class_subject_id' => $classSubjectId, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end,
        ])->assertSessionHasNoErrors();
    }

    return $classSubjectId;
}

function pmgEntry(string $className, int $day): string
{
    return (string) DB::table('ac_schedule_entries as e')->join('ac_classes as c', 'c.id', '=', 'e.class_id')
        ->where('c.name', $className)->where('e.day_of_week', $day)->value('e.id');
}

/**
 * @param  array<string, string>  $data
 */
function pmgRecord(mixed $test, User $user, string $entry, array $data): TestResponse
{
    return $test->actingAs($user)->post(route('academic.teaching.store', ['entry' => $entry]), $data + ['date' => '2026-10-22']);
}

function pmgGuru(mixed $test): void
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $test->guru, 'U1-MA');
}

beforeEach(function () {
    pmgAt($this, '2026-10-22 06:00:00');

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

    $this->guruId = pmgEmployee($this, 'Guru', 'U1-MA', $this->guru);
    $position = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id');
    app(ManagePlacements::class)->add($this->tenant->id, $this->guruId, $this->smp, $position, '2020-07-01', false);
    $this->budi = pmgEmployee($this, 'Budi Piket', 'U3-SMP');
    $this->citra = pmgEmployee($this, 'Citra MA', 'U1-MA');

    $this->smpSubject = pmgClass($this, 'U3-SMP', 'VII-A', 7, 'Matematika', $this->guruId, [[4, '09:00', '10:00'], [3, '07:00', '08:00']]);
    pmgClass($this, 'U1-MA', 'X-A', 10, 'Fiqih', $this->guruId, [[4, '13:00', '14:00']]);
    $this->flushSession();
});

test('jadwal piket per lembaga & hari: guru piket harus pegawai aktif lembaga itu; tercatat di audit; hak akses', function () {
    $this->actingAs($this->operator)->get(route('academic.duty.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/teaching/duty')
            ->where('organizations', [['value' => $this->smp, 'label' => CoreFixtures::node($this->tenant, 'U3-SMP')->name]])
            ->where('yearId', $this->year->id)
            ->has('days', 7)
            ->where('days.3.name', 'Kamis')
            ->where('days.3.employees', []),
        );

    $set = fn (array $ids) => $this->actingAs($this->operator)->post(route('academic.duty.update'), [
        'organization_id' => $this->smp, 'academic_year_id' => $this->year->id, 'day' => 4, 'employee_ids' => $ids,
    ]);

    $set([$this->citra])->assertSessionHasErrors(['employee_ids' => 'Guru piket harus pegawai aktif yang ditempatkan di lembaga ini.']);
    $set([$this->budi, $this->guruId])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->get(route('academic.duty.index'))
        ->assertInertia(fn (Assert $page) => $page->where('days.3.employees', [
            ['id' => $this->budi, 'name' => 'Budi Piket'],
            ['id' => $this->guruId, 'name' => 'Guru MA & MDA'],
        ]));

    $audit = json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::DUTY_ROSTER_UPDATED)->sole()->changes, true);
    expect($audit)->toEqual(['hari' => 'Kamis', 'jumlah_guru' => 2]);

    $set([])->assertSessionHasNoErrors();
    expect(DB::table('ac_duty_rosters')->count())->toBe(0);

    // Kepala SMK: punya izin di SMK saja; lembaga lain 404. Guru tanpa izin & bukan piket: 403.
    $this->flushSession();
    $kepala = DevAccounts::user('kepala.smk');
    $this->actingAs($kepala)->get(route('academic.duty.index', ['lembaga' => $this->smp]))->assertNotFound();
    $this->actingAs($kepala)->post(route('academic.duty.update'), [
        'organization_id' => $this->smp, 'academic_year_id' => $this->year->id, 'day' => 4, 'employee_ids' => [$this->budi],
    ])->assertNotFound();

    pmgGuru($this);
    $this->actingAs($this->guru)->get(route('academic.duty.index'))->assertForbidden();
    $this->actingAs($this->guru)->get(route('academic.teaching.index'))->assertForbidden();
});

test('petugas mencatat per pertemuan di lembaga kelasnya: digantikan wajib pengganti dari lembaga itu & alasan; masa depan ditolak; guru tidak bisa menimpa', function () {
    pmgAt($this, '2026-10-22 10:30:00');

    $this->actingAs($this->operator)->get(route('academic.teaching.index', ['tanggal' => '2026-10-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/teaching/index')
            ->where('date', '2026-10-22')
            ->where('manages', true)
            ->where('canRecord', true)
            // Hanya pertemuan SMP; Fiqih MA tidak tampil di sini.
            ->has('lessons', 1)
            ->where('lessons.0.subject', 'Matematika')
            ->where('lessons.0.start', '09:00')
            ->where('lessons.0.teacher.name', 'Guru MA & MDA')
            ->where('lessons.0.record', null),
        );

    $entry = pmgEntry('VII-A', 4);

    pmgRecord($this, $this->operator, $entry, ['status' => 'KOSONG'])
        ->assertSessionHasErrors(['absence_reason' => 'Pilih alasan guru tidak mengajar.']);
    pmgRecord($this, $this->operator, $entry, ['status' => 'DIGANTIKAN', 'absence_reason' => 'SAKIT'])
        ->assertSessionHasErrors(['substitute_employee_id' => 'Pilih guru pengganti.']);
    pmgRecord($this, $this->operator, $entry, ['status' => 'DIGANTIKAN', 'absence_reason' => 'SAKIT', 'substitute_employee_id' => $this->citra])
        ->assertSessionHasErrors(['substitute_employee_id' => 'Guru pengganti harus pegawai aktif yang ditempatkan di lembaga ini.']);
    pmgRecord($this, $this->operator, $entry, ['status' => 'DIGANTIKAN', 'absence_reason' => 'SAKIT', 'substitute_employee_id' => $this->budi, 'note' => 'Demam sejak pagi'])
        ->assertSessionHasNoErrors();

    $row = DB::table('ac_teaching_records')->sole();
    expect([$row->status, $row->substitute_employee_id, $row->absence_reason, $row->source, $row->organization_id, $row->teacher_employee_id])
        ->toBe(['DIGANTIKAN', $this->budi, 'SAKIT', 'PETUGAS', $this->smp, $this->guruId]);

    $audit = json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::TEACHING_RECORDED)->sole()->changes, true);
    expect($audit['status'])->toBe('Digantikan')
        ->and($audit['pengganti_diisi'])->toBeTrue()
        ->and(json_encode($audit))->not->toContain('Demam');

    $this->actingAs($this->operator)->get(route('academic.teaching.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lessons.0.record.status', 'DIGANTIKAN')
            ->where('lessons.0.record.substitute.name', 'Budi Piket')
            ->where('lessons.0.record.reason_label', 'Sakit')
            ->where('lessons.0.record.note', 'Demam sejak pagi'),
        );

    // Kamis depan belum terjadi; Jumat tidak ada jadwal.
    pmgRecord($this, $this->operator, $entry, ['status' => 'MENGAJAR', 'date' => '2026-10-29'])->assertForbidden();
    pmgRecord($this, $this->operator, $entry, ['status' => 'MENGAJAR', 'date' => '2026-10-16'])->assertForbidden();

    // Rabu kemarin: pertemuan 07:00 tampil dan bisa dicatat operator.
    $this->actingAs($this->operator)->get(route('academic.teaching.index', ['tanggal' => '2026-10-21']))
        ->assertInertia(fn (Assert $page) => $page->has('lessons', 1)->where('lessons.0.start', '07:00'));

    // Guru tidak bisa menimpa catatan petugas.
    pmgGuru($this);
    $this->actingAs($this->guru)->post(route('academic.my-teaching.store', ['entry' => $entry]), ['date' => '2026-10-22'])
        ->assertSessionHasErrors(['form' => 'Pertemuan ini sudah dicatat petugas: Digantikan. Hubungi guru piket atau operator bila keliru.']);

    // Mapel yang sudah punya presensi mengajar tidak bisa dilepas dari kelas.
    $this->flushSession();
    $this->actingAs($this->admin)->delete(route('academic.class-subjects.destroy', ['classSubject' => $this->smpSubject]))
        ->assertSessionHasErrors(['form' => 'Mata pelajaran Matematika sudah punya presensi mengajar, jadi tidak bisa dilepas dari kelas. Hapus jadwalnya saja bila tidak dipakai lagi.']);
});

test('guru menandai "Sudah mengajar" dari Kelas saya di setiap lembaga tempat ia mengajar: paling cepat jam mulai, paling lama 7 hari', function () {
    pmgAt($this, '2026-10-22 08:30:00');
    pmgGuru($this);

    $this->actingAs($this->guru)->get(route('academic.my-classes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today', '2026-10-22')
            ->where('now', '08:30')
            // Hari ini: Matematika SMP 09:00 & Fiqih MA 13:00; Rabu 21 Okt: Matematika 07:00.
            ->where('teaching.0.date', '2026-10-22')
            ->where('teaching.0.organization_id', $this->smp)
            ->where('teaching.1.subject', 'Fiqih')
            ->where('teaching.2.date', '2026-10-21')
            ->where('teaching.2.start', '07:00'),
        );

    $mark = fn (string $entry, string $date) => $this->actingAs($this->guru)->post(route('academic.my-teaching.store', ['entry' => $entry]), ['date' => $date]);
    $smp = pmgEntry('VII-A', 4);

    $mark($smp, '2026-10-22')->assertSessionHasErrors(['form' => 'Pelajaran ini belum dimulai (mulai 09.00).']);

    pmgAt($this, '2026-10-22 09:05:00');
    $mark($smp, '2026-10-22')->assertSessionHasNoErrors();
    $mark($smp, '2026-10-22')->assertSessionHasErrors(['form' => 'Pertemuan ini sudah tercatat Mengajar.']);
    $mark(pmgEntry('VII-A', 3), '2026-10-21')->assertSessionHasNoErrors();
    $mark(pmgEntry('VII-A', 3), '2026-10-14')->assertSessionHasErrors(['form' => 'Guru hanya bisa menandai pertemuan 7 hari terakhir. Hubungi operator lembaga.']);
    $mark(pmgEntry('X-A', 4), '2026-10-21')->assertSessionHasErrors(['form' => 'Tidak ada pertemuan ini pada tanggal tersebut (bukan hari jadwalnya, libur, atau di luar tahun ajaran kelas).']);

    pmgAt($this, '2026-10-22 13:10:00');
    $mark(pmgEntry('X-A', 4), '2026-10-22')->assertSessionHasNoErrors();

    // Tercatat di lembaga kelas masing-masing.
    expect(DB::table('ac_teaching_records')->where('source', 'GURU')->orderBy('date')->orderBy('start_time')->pluck('organization_id')->all())
        ->toBe([$this->smp, $this->smp, CoreFixtures::node($this->tenant, 'U1-MA')->id]);

    // Akun tanpa data pegawai.
    $this->flushSession();
    $this->actingAs($this->admin)->post(route('academic.my-teaching.store', ['entry' => $smp]), ['date' => '2026-10-22'])->assertNotFound();
});

test('guru piket hari ini membuka Presensi mengajar lembaganya: hanya tanggal hari ini', function () {
    $this->actingAs($this->operator)->post(route('academic.duty.update'), [
        'organization_id' => $this->smp, 'academic_year_id' => $this->year->id, 'day' => 4, 'employee_ids' => [$this->guruId],
    ])->assertSessionHasNoErrors();

    pmgAt($this, '2026-10-22 10:30:00');
    pmgGuru($this);

    $this->actingAs($this->guru)->get(route('academic.teaching.index', ['tanggal' => '2026-10-21']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('academic.teachingDuty', true)
            ->where('organizations', [['value' => $this->smp, 'label' => CoreFixtures::node($this->tenant, 'U3-SMP')->name]])
            ->where('manages', false)
            ->where('date', '2026-10-22')
            ->where('canRecord', true)
            ->where('duty.0.label', 'Guru MA & MDA'),
        );

    pmgRecord($this, $this->guru, pmgEntry('VII-A', 4), ['status' => 'MENGAJAR'])->assertSessionHasNoErrors();
    pmgRecord($this, $this->guru, pmgEntry('VII-A', 3), ['status' => 'MENGAJAR', 'date' => '2026-10-21'])->assertForbidden();

    // Jumat: bukan hari piketnya.
    pmgAt($this, '2026-10-23 08:00:00');
    $this->actingAs($this->guru)->get(route('academic.teaching.index'))->assertForbidden();
});
