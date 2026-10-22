<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
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

/*
 * Rapor (PRD-002 §19 AC-5d).
 *
 * "Hari ini" 22 Okt 2026, Semester Ganjil 2026/2027. MA Unit 1, kelas X-A (wali kelas: akun `guru`,
 * nama orangnya "Guru MA & MDA") berisi Ahmad & Aisyah.
 *   Fiqih (FINAL): Ahmad 80 "Baik", Aisyah 66 · Akidah (DRAF): Ahmad 90
 *   Presensi harian: 20 Okt Ahmad S, Aisyah H · 21 Okt Ahmad I, Aisyah A
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
    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    foreach ([['X-A', $employee->id], ['X-B', null]] as [$name, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $this->ma, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-A')->sole();

    foreach (['Ahmad Fauzi', 'Aisyah Putri'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->ma, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'class_id' => $this->class->id,
        ])->assertSessionHasNoErrors();
    }

    foreach (['Akidah', 'Fiqih'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), [
        'subject_ids' => DB::table('ac_subjects')->where('organization_id', $this->ma)->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $row = fn (string $subject): string => (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
    $enrollment = fn (string $name): string => (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
    $this->ahmad = $enrollment('Ahmad Fauzi');
    $this->aisyah = $enrollment('Aisyah Putri');

    $this->actingAs($this->admin)->post(route('academic.grades.store', ['classSubject' => $row('Fiqih')]), ['semester' => $this->term->id, 'grades' => [
        $this->ahmad => ['scores' => ['HARIAN' => '80', 'PTS' => '80', 'PAS' => '80'], 'description' => 'Baik'],
        $this->aisyah => ['scores' => ['HARIAN' => '60', 'PTS' => '70', 'PAS' => '70']],
    ]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $row('Fiqih')]), ['semester' => $this->term->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.store', ['classSubject' => $row('Akidah')]), ['semester' => $this->term->id, 'grades' => [
        $this->ahmad => ['scores' => ['HARIAN' => '90', 'PTS' => '90', 'PAS' => '90']],
    ]])->assertSessionHasNoErrors();

    foreach ([['2026-10-20', 'S', 'H'], ['2026-10-21', 'I', 'A']] as [$date, $ahmad, $aisyah]) {
        $this->actingAs($this->admin)->post(route('academic.attendance.store', ['class' => $this->class->id]), [
            'date' => $date, 'attendance' => [$this->ahmad => ['status' => $ahmad], $this->aisyah => ['status' => $aisyah]],
        ])->assertSessionHasNoErrors();
    }
});

test('daftar rapor: hanya mapel final, rata-rata & presensi semester; catatan wali kelas disimpan (kosong = dihapus) tanpa isi di audit', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.reports.index', ['class' => $this->class->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/grades/report-index')
            ->where('finalSubjects', 1)
            ->where('pending', ['Akidah'])
            ->where('canEditNotes', true)
            ->where('students.0', [
                'enrollment_id' => $this->ahmad, 'name' => 'Ahmad Fauzi', 'nisn' => null, 'left' => false,
                'graded' => 1, 'average' => 80, 'attendance' => ['S' => 1, 'I' => 1, 'A' => 0], 'note' => null,
            ])
            ->where('students.1.attendance', ['S' => 0, 'I' => 0, 'A' => 1]),
        );

    $notes = route('academic.reports.notes', ['class' => $this->class->id]);

    $this->actingAs($this->admin)->post($notes, ['semester' => $this->term->id, 'notes' => [$this->ahmad => '  Pertahankan prestasi. ', $this->aisyah => '']])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/kelas/{$this->class->id}/rapor?semester={$this->term->id}");

    expect(DB::table('ac_report_notes')->where('enrollment_id', $this->ahmad)->value('note'))->toBe('Pertahankan prestasi.')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::REPORT_NOTES_SAVED)->sole()->changes, true))
        ->toEqual(['kelas' => 'X-A', 'semester' => 'Semester Ganjil', 'jumlah' => 1]);

    $this->actingAs($this->admin)->post($notes, ['semester' => $this->term->id, 'notes' => [$this->ahmad => str_repeat('a', 1001)]])
        ->assertSessionHasErrors(['notes.'.$this->ahmad]);
    $this->actingAs($this->admin)->post($notes, ['semester' => $this->term->id, 'notes' => [$this->ahmad => '']])->assertSessionHasNoErrors();

    expect(DB::table('ac_report_notes')->count())->toBe(0);
});

test('halaman cetak: satu siswa atau sekelas; nilai, predikat, deskripsi, presensi, catatan, nama wali kelas', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.reports.notes', ['class' => $this->class->id]), ['semester' => $this->term->id, 'notes' => [$this->ahmad => 'Rajin.']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => $this->ahmad]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/report-cards')
            ->has('report.cards', 1)
            ->where('report.cards.0.subjects', [['name' => 'Fiqih', 'kkm' => 75, 'final' => 80, 'predicate' => 'B', 'description' => 'Baik']])
            ->where('report.cards.0.total', 80)
            ->where('report.cards.0.attendance', ['S' => 1, 'I' => 1, 'A' => 0])
            ->where('report.cards.0.note', 'Rajin.')
            ->where('report.pending', ['Akidah'])
            ->where('report.attendance_kind', 'hari')
            // Nama pegawai mengikuti data orang akun `guru` yang sudah ada (bukan nama di NewEmployeeData).
            ->where('report.homeroom', 'Guru MA & MDA')
            ->where('class.grade', 10),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page->has('report.cards', 2)->where('report.cards.1.subjects.0.predicate', 'D'));

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => (string) Str::uuid()]))
        ->assertNotFound();
});

test('wali kelas dari Kelas saya: melihat, mengisi catatan, mencetak; kelas lain 404; pintu biasa 403; di luar cakupan 404; semester bercatatan tidak bisa dihapus', function () {
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('academic.my-reports.index', ['class' => $this->class->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canEditNotes', true)->where('baseUrl', "/kelas-saya/{$this->class->id}/rapor"));

    $genap = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->where('sequence', 2)->sole();
    $this->actingAs($this->guru)
        ->post(route('academic.my-reports.notes', ['class' => $this->class->id]), ['semester' => $genap->id, 'notes' => [$this->ahmad => 'Catatan semester genap.']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->guru)->get(route('academic.my-reports.print', ['class' => $this->class->id, 'semester' => $genap->id]))->assertOk();

    $other = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-B')->sole();
    $this->actingAs($this->guru)->get(route('academic.my-reports.index', ['class' => $other->id]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.reports.index', ['class' => $this->class->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))->get(route('academic.reports.index', ['class' => $this->class->id]))->assertNotFound();

    $this->actingAs($this->admin)->patch(route('academic.years.update', ['year' => $this->year->id]), [
        'name' => $this->year->name, 'start_date' => $this->year->start_date->toDateString(), 'end_date' => $this->year->end_date->toDateString(),
        'terms' => [['name' => 'Semester Ganjil', 'start_date' => $this->term->start_date->toDateString(), 'end_date' => $this->term->end_date->toDateString()]],
    ])->assertSessionHasErrors(['terms' => 'Semester Genap sudah punya nilai atau catatan rapor, jadi tidak bisa dihapus. Jumlah semester tidak bisa dikurangi.']);
});
