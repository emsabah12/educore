<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Application\Grading\ManageGrades;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
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
 * Lembar nilai (PRD-002 §19 AC-5b).
 *
 * "Hari ini" 22 Okt 2026 → Semester Ganjil 2026/2027. MA Unit 1, kelas X-A berisi Ahmad, Aisyah,
 * Budi. Mapel Fiqih (pengampu: akun `guru`) dan Akidah (tanpa pengampu). Aturan bawaan:
 * KKM 75, Nilai harian 40 / PTS 30 / PAS 30, A ≥ 90, B ≥ 80.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->term = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->where('sequence', 1)->sole();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->sole();

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P'], ['Budi Santoso', 'L']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'class_id' => $this->class->id,
        ])->assertSessionHasNoErrors();
    }

    foreach (['Fiqih', 'Akidah'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), [
        'subject_ids' => DB::table('ac_subjects')->where('organization_id', $this->ma)->orderBy('name')->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $this->fiqih = nlsRow('Fiqih');
    $this->akidah = nlsRow('Akidah');
    $this->actingAs($this->admin)
        ->patch(route('academic.class-subjects.update', ['classSubject' => $this->fiqih]), ['teacher_employee_id' => $this->employee->id])
        ->assertSessionHasNoErrors();

    $this->ahmad = nlsEnrollment('Ahmad Fauzi');
    $this->aisyah = nlsEnrollment('Aisyah Putri');
    $this->budi = nlsEnrollment('Budi Santoso');
});

function nlsRow(string $subject): string
{
    return (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
}

function nlsEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

/**
 * @param  array<string, array{0: string, 1: string, 2: string}>  $scores  id pendaftaran => [HARIAN, PTS, PAS]
 */
function nlsSave(TestCase $test, mixed $user, string $route, string $classSubject, string $term, array $scores): TestResponse
{
    $grades = [];

    foreach ($scores as $enrollment => [$daily, $mid, $end]) {
        $grades[$enrollment] = ['scores' => ['HARIAN' => $daily, 'PTS' => $mid, 'PAS' => $end], 'description' => ''];
    }

    return $test->actingAs($user)->post(route($route, ['classSubject' => $classSubject]), ['semester' => $term, 'grades' => $grades]);
}

test('operator menyimpan nilai: nilai akhir & predikat dihitung, komponen kosong = belum lengkap; tercatat di audit tanpa angka', function () {
    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [
        $this->ahmad => ['80', '85.5', '90'],
        $this->aisyah => ['70', '72,25', ''],
    ])->assertSessionHasNoErrors()->assertRedirect("/kelas/mapel/{$this->fiqih}/nilai?semester={$this->term->id}");

    // 80×40 + 85,5×30 + 90×30 = 8465 → 84,65 → 85 (B).
    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->fiqih]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/grades/sheet')
            ->where('term.value', $this->term->id)
            ->where('sheet.status', 'DRAFT')
            ->where('sheet.complete', 1)
            ->where('sheet.required', 3)
            ->where('sheet.rules.kkm', 75)
            ->where('sheet.students.0.name', 'Ahmad Fauzi')
            ->where('sheet.students.0.scores', ['HARIAN' => 80, 'PTS' => 85.5, 'PAS' => 90])
            ->where('sheet.students.0.final', 85)
            ->where('sheet.students.0.predicate', 'B')
            ->where('sheet.students.1.scores', ['HARIAN' => 70, 'PTS' => 72.25, 'PAS' => null])
            ->where('sheet.students.1.final', null)
            ->where('sheet.students.2.final', null)
            ->where('canEdit', true)
            ->where('canManage', true),
        );

    expect(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::GRADES_SAVED)->sole()->changes, true))->toEqual([
        'kelas' => 'X-A', 'mapel' => 'Fiqih', 'semester' => 'Semester Ganjil', 'siswa' => 2, 'lengkap' => 1,
    ]);

    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->budi => ['101', '80', '80']])
        ->assertSessionHasErrors(['grades' => 'Nilai harian untuk Budi Santoso harus angka 0–100 (paling banyak 2 angka di belakang koma).']);
    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->budi => ['80', '80.555', '80']])
        ->assertSessionHasErrors(['grades' => 'Penilaian tengah semester untuk Budi Santoso harus angka 0–100 (paling banyak 2 angka di belakang koma).']);

    expect(ManageGrades::finalScore(app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma), ['HARIAN' => 74.5, 'PTS' => 74.5, 'PAS' => 74.5]))->toBe(75);
});

test('finalkan: wajib lengkap; sesudah final tidak bisa diubah; operator membuka kembali dengan alasan', function () {
    $finalize = fn () => $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $this->fiqih]), ['semester' => $this->term->id]);

    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->ahmad => ['80', '80', '80']])->assertSessionHasNoErrors();
    $finalize()->assertSessionHasErrors(['form' => 'Nilai belum lengkap untuk 2 siswa: Aisyah Putri, Budi Santoso. Lengkapi semua komponen lalu simpan dulu.']);

    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [
        $this->aisyah => ['60', '70', '70'],
        $this->budi => ['95', '90', '92'],
    ])->assertSessionHasNoErrors();
    $finalize()->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->fiqih, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.status', 'FINAL')
            ->where('sheet.finalized.by', fn ($name) => is_string($name))
            ->where('sheet.students.1.final', 66)
            ->where('sheet.students.1.predicate', 'D')
            ->where('sheet.students.2.predicate', 'A'),
        );

    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->ahmad => ['90', '90', '90']])
        ->assertSessionHasErrors(['form' => 'Nilai ini sudah difinalkan. Minta operator membuka kembali bila perlu diubah.']);

    $reopen = route('academic.grades.reopen', ['classSubject' => $this->fiqih]);
    $this->actingAs($this->admin)->post($reopen, ['semester' => $this->term->id, 'reason' => '  '])
        ->assertSessionHasErrors(['reason' => 'Tulis alasan membuka kembali nilai ini (maks. 500 karakter).']);
    $this->actingAs($this->admin)->post($reopen, ['semester' => $this->term->id, 'reason' => 'Salah input nilai PAS'])->assertSessionHasNoErrors();

    expect(DB::table('ac_grade_sheets')->value('status'))->toBe('DRAFT')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::GRADE_SHEET_REOPENED)->sole()->changes, true))
        ->toEqual(['kelas' => 'X-A', 'mapel' => 'Fiqih', 'semester' => 'Semester Ganjil', 'alasan_diisi' => true])
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::GRADE_SHEET_FINALIZED)->count())->toBe(1);
});

test('guru pengampu mengisi & memfinalkan dari Kelas saya; mapel lain 404; pintu operator 403', function () {
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.show', ['class' => $this->class->id]))
        ->assertInertia(fn (Assert $page) => $page->where('class.taught', [['id' => $this->fiqih, 'name' => 'Fiqih']]));

    $this->actingAs($this->guru)
        ->get(route('academic.my-grades.show', ['classSubject' => $this->fiqih]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canEdit', true)->where('canManage', false)->where('backUrl', "/kelas-saya/{$this->class->id}"));

    nlsSave($this, $this->guru, 'academic.my-grades.store', $this->fiqih, $this->term->id, [
        $this->ahmad => ['80', '80', '80'], $this->aisyah => ['80', '80', '80'], $this->budi => ['80', '80', '80'],
    ])->assertSessionHasNoErrors()->assertRedirect("/kelas-saya/mapel/{$this->fiqih}/nilai?semester={$this->term->id}");

    $this->actingAs($this->guru)->post(route('academic.my-grades.finalize', ['classSubject' => $this->fiqih]), ['semester' => $this->term->id])
        ->assertSessionHasNoErrors();

    expect(DB::table('ac_grade_sheets')->value('status'))->toBe('FINAL');

    $this->actingAs($this->guru)->get(route('academic.my-grades.show', ['classSubject' => $this->akidah]))->assertNotFound();
    nlsSave($this, $this->guru, 'academic.my-grades.store', $this->akidah, $this->term->id, [$this->ahmad => ['80', '80', '80']])->assertNotFound();
    nlsSave($this, $this->guru, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->ahmad => ['80', '80', '80']])->assertForbidden();
});

test('salinan aturan: aturan baru tidak mengubah lembar sampai operator menerapkannya; mapel & semester bernilai tidak bisa dihapus', function () {
    nlsSave($this, $this->admin, 'academic.grades.store', $this->fiqih, $this->term->id, [$this->ahmad => ['80', '80', '90']])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('academic.grading.assign'), [
        'target' => 'jenjang', 'jenjang' => 'MA', 'kkm' => '70', 'grade_a' => '90', 'grade_b' => '80',
        'components' => [['code' => 'TUGAS', 'name' => 'Tugas', 'weight' => '50'], ['code' => 'PAS', 'name' => 'PAS', 'weight' => '50']],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->fiqih]))
        ->assertInertia(fn (Assert $page) => $page->where('sheet.outdated', true)->where('sheet.rules.kkm', 75)->where('sheet.students.0.final', 83));

    $this->actingAs($this->admin)->post(route('academic.grades.apply-rules', ['classSubject' => $this->fiqih]), ['semester' => $this->term->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->fiqih]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.outdated', false)
            ->where('sheet.rules.kkm', 70)
            ->where('sheet.students.0.scores', ['TUGAS' => null, 'PAS' => 90])
            ->where('sheet.students.0.final', null),
        );

    expect(DB::table('audit_logs')->where('action', AcademicAuditAction::GRADE_SHEET_RULES_APPLIED)->count())->toBe(1);

    $this->actingAs($this->admin)->delete(route('academic.class-subjects.destroy', ['classSubject' => $this->fiqih]))
        ->assertSessionHasErrors(['form' => 'Mata pelajaran Fiqih sudah punya nilai, jadi tidak bisa dilepas dari kelas.']);

    // Semester 2 punya nilai → jumlah semester tidak bisa dikurangi.
    $genap = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->where('sequence', 2)->sole();
    nlsSave($this, $this->admin, 'academic.grades.store', $this->akidah, $genap->id, [$this->ahmad => ['80', '', '']])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->patch(route('academic.years.update', ['year' => $this->year->id]), [
        'name' => $this->year->name, 'start_date' => $this->year->start_date->toDateString(), 'end_date' => $this->year->end_date->toDateString(),
        'terms' => [['name' => 'Semester Ganjil', 'start_date' => $this->term->start_date->toDateString(), 'end_date' => $this->term->end_date->toDateString()]],
    ])->assertSessionHasErrors(['terms' => 'Semester Genap sudah punya nilai siswa, jadi tidak bisa dihapus. Jumlah semester tidak bisa dikurangi.']);

    expect(Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->count())->toBe(2);
});
