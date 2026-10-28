<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Guardian\GuardianAccounts;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Anak saya: presensi, nilai final, rapor (PRD-002 §21.3, AC-7b). "Hari ini" Kamis 22 Okt 2026,
 * Semester Ganjil 2026/2027. MA Unit 1 kelas X-A berisi Ahmad (ibu Siti, punya akun) & Aisyah.
 *   Fiqih FINAL: Ahmad 80 · Akidah DRAF: Ahmad 90, Aisyah 70
 *   Presensi harian: 20 Okt Ahmad S, Aisyah H · 21 Okt Ahmad I, Aisyah A
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->term = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $year->id)->where('sequence', 1)->sole();

    foreach (['X-A', 'X-B'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $ma, 'academic_year_id' => $year->id, 'grade' => 10, 'name' => $name, 'homeroom_employee_id' => '',
        ])->assertSessionHasNoErrors();
    }

    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-A')->sole();
    $this->otherClass = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-B')->sole();

    foreach ([['Ahmad Fauzi', [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']]], ['Aisyah Putri', []]] as [$name, $guardians]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $ma, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'class_id' => $this->class->id, 'guardians' => $guardians, 'primary_guardian' => $guardians === [] ? null : 0,
        ])->assertSessionHasNoErrors();
    }

    foreach (['Akidah', 'Fiqih'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $ma, 'name' => $name])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), [
        'subject_ids' => DB::table('ac_subjects')->where('organization_id', $ma)->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $this->row = fn (string $subject): string => (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
    $this->ahmad = anrStudent($this->tenant, 'Ahmad Fauzi');
    $this->aisyah = anrStudent($this->tenant, 'Aisyah Putri');
    $ahmadEnrollment = (string) DB::table('ac_enrollments')->where('student_id', $this->ahmad->id)->value('id');
    $aisyahEnrollment = (string) DB::table('ac_enrollments')->where('student_id', $this->aisyah->id)->value('id');

    $this->actingAs($this->admin)->post(route('academic.grades.store', ['classSubject' => ($this->row)('Fiqih')]), ['semester' => $this->term->id, 'grades' => [
        $ahmadEnrollment => ['scores' => ['HARIAN' => '80', 'PTS' => '80', 'PAS' => '80'], 'description' => 'Baik'],
        $aisyahEnrollment => ['scores' => ['HARIAN' => '60', 'PTS' => '70', 'PAS' => '70']],
    ]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => ($this->row)('Fiqih')]), ['semester' => $this->term->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.store', ['classSubject' => ($this->row)('Akidah')]), ['semester' => $this->term->id, 'grades' => [
        $ahmadEnrollment => ['scores' => ['HARIAN' => '90', 'PTS' => '90', 'PAS' => '90']],
        $aisyahEnrollment => ['scores' => ['HARIAN' => '70', 'PTS' => '70', 'PAS' => '70']],
    ]])->assertSessionHasNoErrors();

    foreach ([['2026-10-20', 'S', 'H'], ['2026-10-21', 'I', 'A']] as [$date, $ahmad, $aisyah]) {
        $this->actingAs($this->admin)->post(route('academic.attendance.store', ['class' => $this->class->id]), [
            'date' => $date, 'attendance' => [$ahmadEnrollment => ['status' => $ahmad, 'note' => 'Catatan guru'], $aisyahEnrollment => ['status' => $aisyah]],
        ])->assertSessionHasNoErrors();
    }

    $link = (string) DB::table('ac_student_guardians')->where('student_id', $this->ahmad->id)->value('id');
    app(GuardianAccounts::class)->provision($this->tenant->id, $this->ahmad->id, $link);
    $this->siti = User::query()->where('username', '081311112222')->sole();
    $this->siti->forceFill(['must_change_password' => false])->save();
    $this->flushSession();
});

function anrStudent(Tenant $tenant, string $name): Student
{
    $membership = Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();

    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', $membership->id)->sole();
}

test('presensi anak: bulan berjalan, rekap & rincian hanya milik anaknya, tanpa keterangan guru', function () {
    $this->actingAs($this->siti)
        ->get(route('academic.my-children.attendance', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-children/attendance')
            ->where('classId', $this->class->id)
            ->where('month', '2026-10')
            ->where('months.0', ['value' => '2026-10', 'label' => 'Oktober 2026'])
            ->where('attendance.kind', 'hari')
            ->where('attendance.totals', ['H' => 0, 'S' => 1, 'I' => 1, 'A' => 0])
            ->where('attendance.days', [
                ['date' => '2026-10-20', 'status' => 'S', 'label' => 'Sakit', 'subject' => null, 'time' => null],
                ['date' => '2026-10-21', 'status' => 'I', 'label' => 'Izin', 'subject' => null, 'time' => null],
            ]));

    $this->get(route('academic.my-children.attendance', ['student' => $this->ahmad->id, 'bulan' => '2026-09']))
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-09')->where('attendance.days', []));
});

test('nilai: hanya mapel final; rapor baru bisa dicetak setelah semua mapel final', function () {
    $this->actingAs($this->siti)
        ->get(route('academic.my-children.grades', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-children/grades')
            ->where('term.value', $this->term->id)
            ->has('subjects', 1)
            ->where('subjects.0.name', 'Fiqih')
            ->where('subjects.0.final', 80)
            ->where('pending', ['Akidah'])
            ->where('attendance', ['S' => 1, 'I' => 1, 'A' => 0])
            ->where('canPrint', false));

    $report = route('academic.my-children.report', ['student' => $this->ahmad->id, 'kelas' => $this->class->id, 'semester' => $this->term->id]);
    $this->get($report)->assertNotFound();

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => ($this->row)('Akidah')]), ['semester' => $this->term->id])->assertSessionHasNoErrors();
    $this->flushSession();

    $this->actingAs($this->siti)->get(route('academic.my-children.grades', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->has('subjects', 2)->where('pending', [])->where('canPrint', true));

    $this->get($report)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/report-cards')
            ->has('report.cards', 1)
            ->where('report.cards.0.name', 'Ahmad Fauzi')
            ->where('backUrl', "/anak-saya/{$this->ahmad->id}/nilai?kelas={$this->class->id}&semester={$this->term->id}"));
});

test('bukan anaknya atau bukan kelasnya: 404', function () {
    $this->actingAs($this->siti);

    $this->get(route('academic.my-children.attendance', ['student' => $this->aisyah->id]))->assertNotFound();
    $this->get(route('academic.my-children.grades', ['student' => $this->aisyah->id]))->assertNotFound();
    $this->get(route('academic.my-children.report', ['student' => $this->aisyah->id, 'kelas' => $this->class->id, 'semester' => $this->term->id]))->assertNotFound();
    $this->get(route('academic.my-children.report', ['student' => $this->ahmad->id, 'kelas' => $this->otherClass->id, 'semester' => $this->term->id]))->assertNotFound();

    // Kelas lain di URL presensi: kembali ke kelas anaknya sendiri.
    $this->get(route('academic.my-children.attendance', ['student' => $this->ahmad->id, 'kelas' => $this->otherClass->id]))
        ->assertInertia(fn (Assert $page) => $page->where('classId', $this->class->id));
});
