<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassSubject;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Subject\Subject;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Salin mapel & pengampu dari kelas lain (PRD-002 §18 P3).
 *
 * SMP Unit 3, tahun berjalan: VII-A berisi Fiqih (pengampu Budi — data lama, Budi pegawai MTs),
 * IPA Tujuh (khusus tingkat 7), Matematika (7–9, pengampu Ani), PJOK (lalu dinonaktifkan);
 * VII-B berisi Fiqih; VIII-A tanpa mapel. Tahun berikutnya: VII-A dan VIII-A (kosong).
 * MTs Unit 1, tahun berjalan: VII-MTs berisi Matematika MTs.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS')->id;

    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $first = (int) substr($this->current->name, 0, 4);
    $this->nextYear = new AcademicYear;
    $this->nextYear->forceFill([
        'tenant_id' => $this->tenant->id, 'calendar_id' => $this->current->calendar_id, 'name' => ($first + 1).'/'.($first + 2),
        'start_date' => ($first + 1).'-07-01', 'end_date' => ($first + 2).'-06-30', 'is_current' => false,
    ])->save();

    $this->ani = slnEmployee($this->tenant, 'Ani Guru', 'U3-SMP');
    $this->budi = slnEmployee($this->tenant, 'Budi Guru', 'U1-MTS');

    foreach ([
        ['VII-A', 7, $this->smp, $this->current], ['VII-B', 7, $this->smp, $this->current], ['VIII-A', 8, $this->smp, $this->current],
        ['VII-MTs', 7, $this->mts, $this->current],
        ['VII-A', 7, $this->smp, $this->nextYear], ['VIII-A', 8, $this->smp, $this->nextYear],
    ] as [$name, $grade, $organization, $year]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $year->id, 'grade' => $grade, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    foreach ([
        [$this->smp, ['name' => 'Matematika', 'code' => 'MTK', 'grade_min' => '7', 'grade_max' => '9']],
        [$this->smp, ['name' => 'Fiqih']],
        [$this->smp, ['name' => 'IPA Tujuh', 'grade_min' => '7', 'grade_max' => '7']],
        [$this->smp, ['name' => 'PJOK']],
        [$this->mts, ['name' => 'Matematika']],
    ] as [$organization, $values]) {
        $this->actingAs($this->admin)
            ->post(route('academic.subjects.store'), array_merge(['organization_id' => $organization], $values))
            ->assertSessionHasNoErrors();
    }

    $smpSubjects = fn (string ...$names): array => array_map(fn (string $name): string => slnSubject($this->smp, $name)->id, $names);

    foreach ([
        [slnClass('VII-A', $this->current), $smpSubjects('Matematika', 'Fiqih', 'IPA Tujuh', 'PJOK')],
        [slnClass('VII-B', $this->current), $smpSubjects('Fiqih')],
        [slnClass('VII-MTs', $this->current), [slnSubject($this->mts, 'Matematika')->id]],
    ] as [$class, $subjectIds]) {
        $this->actingAs($this->admin)
            ->post(route('academic.class-subjects.store', ['class' => $class->id]), ['subject_ids' => $subjectIds])
            ->assertSessionHasNoErrors();
    }

    $source = slnClass('VII-A', $this->current);
    $this->actingAs($this->admin)
        ->patch(route('academic.class-subjects.update', ['classSubject' => slnRow($source, 'Matematika')->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasNoErrors();

    // Data lama: pengampu Fiqih pegawai lembaga lain (tidak bisa lewat aplikasi sekarang).
    DB::table('ac_class_subjects')->where('id', slnRow($source, 'Fiqih')->id)->update(['teacher_employee_id' => $this->budi->id]);

    slnSubject($this->smp, 'PJOK')->forceFill(['is_active' => false])->save();
});

function slnEmployee(Tenant $tenant, string $name, string $organizationCode): Employee
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id, membershipId: null, name: $name, gender: Gender::Female, birthPlace: null, birthDate: null,
        phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($tenant, $organizationCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));
}

function slnClass(string $name, AcademicYear $year): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->where('academic_year_id', $year->id)->sole();
}

function slnSubject(string $organizationId, string $name): Subject
{
    return Subject::query()->withoutGlobalScope('tenant')->where('organization_id', $organizationId)->where('name', $name)->sole();
}

function slnRow(SchoolClass $class, string $subjectName): ClassSubject
{
    return ClassSubject::query()->withoutGlobalScope('tenant')->where('class_id', $class->id)->where('subject_id', slnSubject($class->organization_id, $subjectName)->id)->sole();
}

function slnCopy(TestCase $test, mixed $user, SchoolClass $target, string $sourceId): TestResponse
{
    return $test->actingAs($user)->post(route('academic.class-subjects.copy', ['class' => $target->id]), ['source_class_id' => $sourceId]);
}

test('detail kelas: pilihan kelas sumber = kelas lain di lembaga yang sama yang punya mapel; saran = kelas setingkat tahun sebelumnya', function () {
    $year = $this->current->name;

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => slnClass('VII-A', $this->nextYear)->id, 'salin' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('copySources', [
                'options' => [
                    ['value' => slnClass('VII-A', $this->current)->id, 'label' => "VII-A — {$year}"],
                    ['value' => slnClass('VII-B', $this->current)->id, 'label' => "VII-B — {$year}"],
                ],
                'suggested' => slnClass('VII-A', $this->current)->id,
            ])
            ->where('openCopy', true),
        );

    // Tingkat 8: tidak ada kelas setingkat tahun lalu yang punya mapel → tanpa saran.
    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => slnClass('VIII-A', $this->nextYear)->id]))
        ->assertInertia(fn (Assert $page) => $page->where('copySources.suggested', null)->where('openCopy', false));
});

test('salin: mapel aktif yang cocok tingkatnya ikut, pengampu ikut bila masih bertugas di lembaga itu, sisanya dilewati; tercatat di audit', function () {
    $target = slnClass('VIII-A', $this->nextYear);

    slnCopy($this, $this->operator, $target, slnClass('VII-A', $this->current)->id)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(slnRow($target, 'Matematika')->teacher_employee_id)->toBe($this->ani->id)
        ->and(slnRow($target, 'Fiqih')->teacher_employee_id)->toBeNull()
        ->and(slnRow($target, 'Fiqih')->academic_year_id)->toBe($this->nextYear->id)
        ->and(DB::table('ac_class_subjects')->where('class_id', $target->id)->count())->toBe(2)
        ->and(DB::table('ac_schedule_entries')->count())->toBe(0)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECTS_ADDED)->whereNotNull('changes->disalin_dari')->sole()->changes, true))
        ->toEqual([
            'kelas' => 'VIII-A', 'jumlah' => 2, 'mapel' => ['Fiqih', 'Matematika'],
            'disalin_dari' => "VII-A ({$this->current->name})", 'dilewati' => 2, 'pengampu_ikut' => 1,
        ]);

    // Disalin lagi: semuanya sudah ada / tidak cocok → ditolak dengan pesan jelas, tidak ada yang berubah.
    slnCopy($this, $this->operator, $target, slnClass('VII-A', $this->current)->id)
        ->assertSessionHasErrors(['source_class_id' => "Tidak ada mata pelajaran dari kelas VII-A ({$this->current->name}) yang bisa disalin: semuanya sudah ada di kelas ini, nonaktif, atau bukan untuk tingkat 8."]);

    expect(DB::table('ac_class_subjects')->where('class_id', $target->id)->count())->toBe(2);
});

test('sumber harus kelas lain di lembaga yang sama dan punya mapel; pengelola lembaga lain tidak bisa', function () {
    $target = slnClass('VII-A', $this->nextYear);

    slnCopy($this, $this->admin, $target, slnClass('VII-MTs', $this->current)->id)
        ->assertSessionHasErrors(['source_class_id' => 'Kelas sumber harus kelas lain di lembaga yang sama.']);
    slnCopy($this, $this->admin, $target, $target->id)
        ->assertSessionHasErrors(['source_class_id' => 'Pilih kelas lain sebagai sumber.']);
    slnCopy($this, $this->admin, $target, slnClass('VIII-A', $this->current)->id)
        ->assertSessionHasErrors(['source_class_id' => "Kelas VIII-A ({$this->current->name}) belum punya mata pelajaran."]);
    slnCopy($this, $this->admin, $target, '')
        ->assertSessionHasErrors(['source_class_id' => 'Pilih kelas sumber.']);

    // Operator SMP tidak menjangkau kelas MTs.
    slnCopy($this, $this->operator, slnClass('VII-MTs', $this->current), slnClass('VII-A', $this->current)->id)->assertNotFound();

    expect(DB::table('ac_class_subjects')->where('class_id', $target->id)->count())->toBe(0);
});
