<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Cuti & guru pengganti (PRD-002 §20.3, AC-6b).
 *
 * "Hari ini" Kamis 22 Okt 2026. SMP Unit 3:
 *   VII-A Matematika — Guru (akun `guru`, penempatan utama MA Unit 1, juga di SMP): Kamis 09:00–10:00
 *   VII-B IPA — Budi: Kamis 09:30–10:30
 * Dani = pegawai SMP tanpa jadwal mengajar; guru piket SMP setiap Kamis.
 */

function pgtAt(mixed $test, string $moment): void
{
    $test->travelTo(CarbonImmutable::parse($moment, BusinessClock::timezone()));
}

function pgtEmployee(mixed $test, string $name, string $organizationCode, ?User $user = null): string
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

function pgtClass(mixed $test, string $name, string $subject, string $teacherId, string $start, string $end): void
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

function pgtEntry(string $className): string
{
    return (string) DB::table('ac_schedule_entries as e')->join('ac_classes as c', 'c.id', '=', 'e.class_id')->where('c.name', $className)->value('e.id');
}

function pgtLeave(mixed $test, string $employeeId, string $date): void
{
    $test->flushSession();
    $test->actingAs($test->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $employeeId, 'leave_type_id' => $test->annual->id, 'start_date' => $date, 'end_date' => $date, 'reason' => 'Keperluan keluarga',
    ])->assertSessionHasNoErrors();
    $test->flushSession();
}

/**
 * @param  array<string, string>  $data
 */
function pgtAssign(mixed $test, string $className, array $data): TestResponse
{
    return $test->actingAs($test->operator)->post(route('academic.teaching.store', ['entry' => pgtEntry($className)]), $data + [
        'date' => '2026-10-29', 'status' => 'DIGANTIKAN', 'absence_reason' => 'CUTI',
    ]);
}

beforeEach(function () {
    pgtAt($this, '2026-10-22 06:00:00');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->guru = DevAccounts::user('guru');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();

    $this->guruId = pgtEmployee($this, 'Guru', 'U1-MA', $this->guru);
    $position = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id');
    app(ManagePlacements::class)->add($this->tenant->id, $this->guruId, $this->smp, $position, '2020-07-01', false);
    $this->budi = pgtEmployee($this, 'Budi IPA', 'U3-SMP');
    $this->dani = pgtEmployee($this, 'Dani Piket', 'U3-SMP');

    pgtClass($this, 'VII-A', 'Matematika', $this->guruId, '09:00', '10:00');
    pgtClass($this, 'VII-B', 'IPA', $this->budi, '09:30', '10:30');

    $this->actingAs($this->operator)->post(route('academic.duty.update'), [
        'organization_id' => $this->smp, 'academic_year_id' => $this->year->id, 'day' => 4, 'employee_ids' => [$this->dani],
    ])->assertSessionHasNoErrors();
    $this->flushSession();
});

test('cuti disetujui menandai pertemuan "Perlu pengganti"; saran = guru piket, yang bentrok tidak bisa dipilih; tunjuk sebelum hari-H & batalkan', function () {
    pgtLeave($this, $this->guruId, '2026-10-29');

    $this->actingAs($this->operator)->get(route('academic.substitutes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/teaching/substitutes')
            ->where('aheadDays', 7)
            ->has('days', 1)
            ->where('days.0.date', '2026-10-29')
            ->where('days.0.duty', ['Dani Piket'])
            ->has('days.0.lessons', 1)
            ->where('days.0.lessons.0.subject', 'Matematika')
            ->where('days.0.lessons.0.leave', 'Cuti tahunan')
            ->where('days.0.lessons.0.record', null)
            ->where('days.0.candidates.'.pgtEntry('VII-A').'|2026-10-29', [
                ['value' => $this->dani, 'label' => 'Dani Piket', 'duty' => true, 'available' => true, 'note' => null],
                ['value' => $this->budi, 'label' => 'Budi IPA', 'duty' => false, 'available' => false, 'note' => 'Mengajar VII-B 09.30–10.30'],
            ]),
        );

    pgtAssign($this, 'VII-A', ['substitute_employee_id' => $this->budi])
        ->assertSessionHasErrors(['substitute_employee_id' => 'Guru pengganti ini tidak tersedia pada jam tersebut: mengajar VII-B 09.30–10.30.']);
    pgtAssign($this, 'VII-A', ['substitute_employee_id' => $this->dani, 'note' => 'Latihan soal bab 3'])->assertSessionHasNoErrors();

    $row = DB::table('ac_teaching_records')->sole();
    expect([$row->status, $row->substitute_employee_id, $row->absence_reason, substr((string) $row->date, 0, 10)])
        ->toBe(['DIGANTIKAN', $this->dani, 'CUTI', '2026-10-29']);

    $this->actingAs($this->operator)->get(route('academic.substitutes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('days.0.lessons.0.record.substitute.name', 'Dani Piket'));

    // Lebih dari 7 hari ke depan, atau status selain Digantikan untuk tanggal yang akan datang: ditolak.
    pgtAssign($this, 'VII-A', ['substitute_employee_id' => $this->dani, 'date' => '2026-11-05'])->assertForbidden();
    pgtAssign($this, 'VII-A', ['status' => 'KOSONG'])->assertForbidden();

    $this->actingAs($this->operator)->post(route('academic.substitutes.clear', ['entry' => pgtEntry('VII-A')]), ['date' => '2026-10-29'])
        ->assertSessionHasNoErrors();
    expect(DB::table('ac_teaching_records')->count())->toBe(0);
    $this->actingAs($this->operator)->post(route('academic.substitutes.clear', ['entry' => pgtEntry('VII-A')]), ['date' => '2026-10-29'])
        ->assertSessionHasErrors(['form' => 'Pertemuan ini belum punya guru pengganti.']);
});

test('calon yang cuti atau sudah menggantikan di jam beririsan tidak tersedia', function () {
    pgtLeave($this, $this->guruId, '2026-10-29');
    pgtLeave($this, $this->budi, '2026-10-29');

    pgtAssign($this, 'VII-A', ['substitute_employee_id' => $this->dani])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->get(route('academic.substitutes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('days.0.lessons', 2)
            ->where('days.0.candidates.'.pgtEntry('VII-B').'|2026-10-29', fn ($candidates) => collect($candidates)->firstWhere('value', $this->dani)['note'] === 'Menggantikan di VII-A 09.00–10.00'
                && collect($candidates)->firstWhere('value', $this->guruId)['note'] === 'Cuti (Cuti tahunan)'),
        );

    pgtAssign($this, 'VII-B', ['substitute_employee_id' => $this->dani])
        ->assertSessionHasErrors(['substitute_employee_id' => 'Guru pengganti ini tidak tersedia pada jam tersebut: menggantikan di VII-A 09.00–10.00.']);
});

test('pengampu cuti hari ini tampil di Presensi mengajar & Kelas saya; guru piket hanya melihat hari ini', function () {
    pgtLeave($this, $this->guruId, '2026-10-22');

    pgtAt($this, '2026-10-22 10:30:00');
    $this->actingAs($this->operator)->get(route('academic.teaching.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lessons.0.subject', 'Matematika')
            ->where('lessons.0.leave', 'Cuti tahunan')
            ->where('lessons.1.leave', null)
            ->has('candidates.'.pgtEntry('VII-A').'|2026-10-22'),
        );
    $this->flushSession();

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('academic.my-classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('teaching.0.leave', 'Cuti tahunan'));

    // Guru (bukan piket, tanpa izin) tidak membuka Perlu pengganti.
    $this->actingAs($this->guru)->get(route('academic.substitutes.index'))->assertForbidden();
});
