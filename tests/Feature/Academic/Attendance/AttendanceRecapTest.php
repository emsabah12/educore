<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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

/*
 * Rekap presensi harian (AC-4b, PRD-002 §17).
 *
 * Akun `guru` = pegawai MA Unit 1, wali kelas X-A berisi Ahmad & Aisyah. Presensi diisi admin:
 *   3 hari lalu: Ahmad S, Aisyah H · 2 hari lalu: Ahmad H, Aisyah A
 *   kemarin Aisyah pindah ke XI-A · kemarin: Ahmad H
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
    $daysAgo = fn (int $days): string => BusinessClock::today()->subDays($days)->toDateString();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA & MDA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    foreach ([['X-A', 10, $employee->id], ['XI-A', 11, null]] as [$name, $grade, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $this->ma, 'academic_year_id' => $this->current->id, 'grade' => $grade, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
            'class_id' => rkpClass('X-A')->id,
        ])->assertSessionHasNoErrors();
    }

    $ahmad = rkpEnrollment('Ahmad Fauzi');
    $aisyah = rkpEnrollment('Aisyah Putri');
    $save = fn (string $date, array $entries) => $this->actingAs($this->admin)
        ->post(route('academic.attendance.store', ['class' => rkpClass('X-A')->id]), ['date' => $date, 'attendance' => $entries])
        ->assertSessionHasNoErrors();

    $save($daysAgo(3), [$ahmad => ['status' => 'S'], $aisyah => ['status' => 'H']]);
    $save($daysAgo(2), [$ahmad => ['status' => 'H'], $aisyah => ['status' => 'A']]);

    $member = (string) DB::table('ac_class_members')->where('enrollment_id', $aisyah)->value('id');
    $this->actingAs($this->admin)
        ->post(route('academic.class-members.move', ['member' => $member]), ['target_class_id' => rkpClass('XI-A')->id, 'date' => $daysAgo(1)])
        ->assertSessionHasNoErrors();

    $save($daysAgo(1), [$ahmad => ['status' => 'H']]);
});

function rkpClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function rkpEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

test('rekap tahun ajaran: jumlah H/S/I/A & persen hadir per siswa; siswa yang pindah tetap tampil dengan presensi selama di kelas', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id, 'periode' => 'tahun']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/attendance/recap')
            ->where('period.value', 'tahun')
            ->where('recap.days', 3)
            ->has('recap.students', 2)
            ->where('recap.students.0.name', 'Ahmad Fauzi')
            ->where('recap.students.0.H', 2)
            ->where('recap.students.0.S', 1)
            ->where('recap.students.0.total', 3)
            ->where('recap.students.0.percent', 66.7)
            ->where('recap.students.1.name', 'Aisyah Putri')
            ->where('recap.students.1.A', 1)
            ->where('recap.students.1.total', 2)
            // Props dikirim sebagai JSON: 50.0 menjadi 50.
            ->where('recap.students.1.percent', 50)
            ->where('recap.totals', ['H' => 3, 'S' => 1, 'I' => 0, 'A' => 1]),
        );

    // Kelas baru Aisyah: belum ada presensi → persen kosong, bukan 0.
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => rkpClass('XI-A')->id, 'periode' => 'tahun']))
        ->assertInertia(fn (Assert $page) => $page->where('recap.days', 0)->where('recap.students.0.name', 'Aisyah Putri')->where('recap.students.0.percent', null));
});

test('pilihan periode: tahun ajaran, semester, dan bulan yang sudah mulai; bawaan bulan berjalan; periode asal-asalan diabaikan', function () {
    $month = 'bulan-'.BusinessClock::today()->format('Y-m');

    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('periods.0.value', 'tahun')
            ->where('periods.1.value', 'semester-1')
            ->where('period.value', $month),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id, 'periode' => 'bulan-1999-01']))
        ->assertInertia(fn (Assert $page) => $page->where('period.value', $month));
});

test('siapa melihat rekap: izin lihat siswa di lembaga kelas; wali kelas lewat Kelas saya; selain itu 403/404', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id, 'periode' => 'tahun']))
        ->assertOk();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id]))
        ->assertNotFound();

    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('academic.my-attendance.recap', ['class' => rkpClass('X-A')->id, 'periode' => 'tahun']))
        ->assertInertia(fn (Assert $page) => $page->where('recap.days', 3)->where('backUrl', '/kelas-saya/'.rkpClass('X-A')->id));
    $this->actingAs($this->guru)->get(route('academic.my-attendance.recap', ['class' => rkpClass('XI-A')->id]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.attendance.recap', ['class' => rkpClass('X-A')->id]))->assertForbidden();
});
