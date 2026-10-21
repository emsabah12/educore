<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Mata pelajaran kelas & guru pengampu (AC-3b, PRD-002 §16).
 *
 * Data uji: kelas VII-A (tingkat 7) di SMP Unit 3 dan VII-MTs di MTs Unit 1, tahun berjalan.
 * Mapel SMP: Matematika (MTK, 7–9), Fiqih (semua tingkat), Kimia (10–12), PJOK (nonaktif).
 * Mapel MTs: Matematika. Pegawai: Ani (SMP Unit 3), Budi (MTs Unit 1).
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

    $this->ani = kmpEmployee($this->tenant, 'Ani Guru', 'U3-SMP');
    $this->budi = kmpEmployee($this->tenant, 'Budi Guru', 'U1-MTS');

    foreach ([['VII-A', $this->smp], ['VII-MTs', $this->mts]] as [$name, $organization]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $this->current->id, 'grade' => 7, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    foreach ([
        [$this->smp, ['name' => 'Matematika', 'code' => 'MTK', 'grade_min' => '7', 'grade_max' => '9']],
        [$this->smp, ['name' => 'Fiqih']],
        [$this->smp, ['name' => 'Kimia', 'grade_min' => '10', 'grade_max' => '12']],
        [$this->smp, ['name' => 'PJOK']],
        [$this->mts, ['name' => 'Matematika']],
    ] as [$organization, $values]) {
        $this->actingAs($this->admin)
            ->post(route('academic.subjects.store'), array_merge(['organization_id' => $organization], $values))
            ->assertSessionHasNoErrors();
    }

    kmpSubject($this->smp, 'PJOK')->forceFill(['is_active' => false])->save();
});

function kmpEmployee(Tenant $tenant, string $name, string $organizationCode): Employee
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

function kmpClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function kmpSubject(string $organizationId, string $name): Subject
{
    return Subject::query()->withoutGlobalScope('tenant')->where('organization_id', $organizationId)->where('name', $name)->sole();
}

function kmpRow(string $class, string $subjectId): ClassSubject
{
    return ClassSubject::query()->withoutGlobalScope('tenant')->where('class_id', kmpClass($class)->id)->where('subject_id', $subjectId)->sole();
}

/**
 * @param  list<string>  $subjectIds
 */
function kmpAdd(TestCase $test, mixed $user, string $class, array $subjectIds): TestResponse
{
    return $test->actingAs($user)->post(route('academic.class-subjects.store', ['class' => kmpClass($class)->id]), ['subject_ids' => $subjectIds]);
}

// ── Detail & tambah ───────────────────────────────────────────────────────────

test('detail kelas: pilihan mapel = aktif, cocok tingkatnya, belum ada di kelas; pilihan pengampu = pegawai aktif lembaga itu', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => kmpClass('VII-A')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('subjects', [])
            ->where('subjectOptions', [
                ['value' => kmpSubject($this->smp, 'Fiqih')->id, 'label' => 'Fiqih'],
                ['value' => kmpSubject($this->smp, 'Matematika')->id, 'label' => 'Matematika (MTK)'],
            ])
            ->where('teachers', [['id' => $this->ani->id, 'name' => 'Ani Guru']]),
        );
});

test('tambah beberapa mapel sekaligus; pilihan berkurang; tercatat di audit', function () {
    kmpAdd($this, $this->operator, 'VII-A', [kmpSubject($this->smp, 'Matematika')->id, kmpSubject($this->smp, 'Fiqih')->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => kmpClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('subjects', 2)
            ->where('subjects.0.name', 'Fiqih')
            ->where('subjects.0.teacher', null)
            ->where('subjects.1.code', 'MTK')
            ->where('subjectOptions', []),
        );

    expect(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECTS_ADDED)->sole()->changes, true))
        ->toEqual(['kelas' => 'VII-A', 'jumlah' => 2, 'mapel' => ['Matematika', 'Fiqih']]);
});

test('tambah: semua atau tidak sama sekali; mapel salah tingkat, nonaktif, lembaga lain, atau sudah ada disebut sekaligus', function () {
    kmpAdd($this, $this->operator, 'VII-A', [kmpSubject($this->smp, 'Fiqih')->id])->assertSessionHasNoErrors();

    kmpAdd($this, $this->operator, 'VII-A', [
        kmpSubject($this->smp, 'Matematika')->id,
        kmpSubject($this->smp, 'Kimia')->id,
        kmpSubject($this->smp, 'PJOK')->id,
        kmpSubject($this->mts, 'Matematika')->id,
        kmpSubject($this->smp, 'Fiqih')->id,
    ])->assertSessionHasErrors(['subject_ids' => '4 mata pelajaran tidak bisa ditambahkan: Kimia (bukan untuk tingkat 7), PJOK (nonaktif), mata pelajaran yang tidak ada di lembaga ini, Fiqih (sudah ada di kelas ini).']);

    expect(DB::table('ac_class_subjects')->where('class_id', kmpClass('VII-A')->id)->count())->toBe(1);
});

// ── Pengampu ──────────────────────────────────────────────────────────────────

test('guru pengampu: pegawai aktif lembaga itu; dari lembaga lain ditolak; yang berhenti tetap tercatat dan ditandai', function () {
    kmpAdd($this, $this->operator, 'VII-A', [kmpSubject($this->smp, 'Matematika')->id])->assertSessionHasNoErrors();
    $row = kmpRow('VII-A', kmpSubject($this->smp, 'Matematika')->id);

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => $this->budi->id])
        ->assertSessionHasErrors(['teacher_employee_id' => 'Guru pengampu harus pegawai aktif yang ditempatkan di lembaga ini.']);

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasNoErrors();

    expect($row->refresh()->teacher_employee_id)->toBe($this->ani->id)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECT_TEACHER_CHANGED)->sole()->changes, true))
        ->toEqual(['kelas' => 'VII-A', 'mapel' => 'Matematika', 'pengampu_diisi' => true]);

    // Ani berhenti: tetap tercatat, ditandai, dan menyimpan pengampu yang sama tidak ditolak.
    DB::table('hr_employees')->where('id', $this->ani->id)->update(['status' => 'BERHENTI']);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => kmpClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('subjects.0.teacher.name', 'Ani Guru')->where('subjects.0.teacher.active', false));

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => ''])
        ->assertSessionHasNoErrors();

    expect($row->refresh()->teacher_employee_id)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECT_TEACHER_CHANGED)->count())->toBe(2);
});

// ── Lepas, hapus mapel, hapus kelas ───────────────────────────────────────────

test('lepas mapel dari kelas; mapel yang dipakai di kelas tidak bisa dihapus; kelas kosong tetap bisa dihapus', function () {
    $math = kmpSubject($this->smp, 'Matematika');
    $fiqih = kmpSubject($this->smp, 'Fiqih');
    kmpAdd($this, $this->operator, 'VII-A', [$math->id, $fiqih->id])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->get(route('academic.subjects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('subjects.0.name', 'Fiqih')->where('subjects.0.deletable', false)->where('subjects.2.name', 'Matematika')->where('subjects.2.deletable', false));

    $this->actingAs($this->operator)
        ->delete(route('academic.subjects.destroy', ['subject' => $math->id]))
        ->assertSessionHasErrors(['form' => 'Mata pelajaran ini sudah dipakai di kelas, jadi tidak bisa dihapus. Nonaktifkan saja.']);

    $this->actingAs($this->operator)
        ->delete(route('academic.class-subjects.destroy', ['classSubject' => kmpRow('VII-A', $math->id)->id]))
        ->assertSessionHasNoErrors();

    expect(DB::table('ac_class_subjects')->where('subject_id', $math->id)->exists())->toBeFalse()
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_SUBJECT_REMOVED)->sole()->changes, true))
        ->toEqual(['kelas' => 'VII-A', 'mapel' => 'Matematika', 'jadwal_dihapus' => 0]);

    $this->actingAs($this->operator)->delete(route('academic.subjects.destroy', ['subject' => $math->id]))->assertSessionHasNoErrors();

    // Kelas tanpa anggota boleh dihapus walau punya mapel; mapel kelasnya ikut terhapus.
    $this->actingAs($this->operator)->delete(route('academic.classes.destroy', ['class' => kmpClass('VII-A')->id]))->assertSessionHasNoErrors();

    expect(DB::table('ac_class_subjects')->count())->toBe(0)
        ->and(Subject::query()->withoutGlobalScope('tenant')->whereKey($fiqih->id)->exists())->toBeTrue();
});

test('kelas tahun ajaran yang sudah lewat: mapel & pengampu hanya bisa dilihat', function () {
    kmpAdd($this, $this->operator, 'VII-A', [kmpSubject($this->smp, 'Fiqih')->id])->assertSessionHasNoErrors();
    $row = kmpRow('VII-A', kmpSubject($this->smp, 'Fiqih')->id);

    $this->current->forceFill(['is_current' => false, 'end_date' => BusinessClock::today()->subDay()->toDateString()])->save();

    $past = 'Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat, tidak bisa diubah.';

    kmpAdd($this, $this->operator, 'VII-A', [kmpSubject($this->smp, 'Matematika')->id])->assertSessionHasErrors(['form' => $past]);
    $this->actingAs($this->operator)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => $this->ani->id])
        ->assertSessionHasErrors(['form' => $past]);
    $this->actingAs($this->operator)
        ->delete(route('academic.class-subjects.destroy', ['classSubject' => $row->id]))
        ->assertSessionHasErrors(['form' => $past]);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => kmpClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('subjects', 1)->where('canManage', false)->where('subjectOptions', []));
});

test('hak akses: kelas di luar cakupan 404, tanpa izin kelola 403', function () {
    kmpAdd($this, $this->admin, 'VII-MTs', [kmpSubject($this->mts, 'Matematika')->id])->assertSessionHasNoErrors();
    $row = kmpRow('VII-MTs', kmpSubject($this->mts, 'Matematika')->id);
    $kepala = DevAccounts::user('kepala.unit1');

    kmpAdd($this, $this->operator, 'VII-MTs', [kmpSubject($this->mts, 'Matematika')->id])->assertNotFound();
    kmpAdd($this, $kepala, 'VII-MTs', [kmpSubject($this->mts, 'Matematika')->id])->assertForbidden();

    $this->actingAs($this->operator)->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => ''])->assertNotFound();
    $this->actingAs($kepala)->patch(route('academic.class-subjects.update', ['classSubject' => $row->id]), ['teacher_employee_id' => $this->budi->id])->assertForbidden();
    $this->actingAs($kepala)->delete(route('academic.class-subjects.destroy', ['classSubject' => $row->id]))->assertForbidden();
    $this->actingAs($this->operator)->delete(route('academic.class-subjects.destroy', ['classSubject' => (string) Str::uuid7()]))->assertNotFound();

    expect($row->refresh()->teacher_employee_id)->toBeNull();
});
