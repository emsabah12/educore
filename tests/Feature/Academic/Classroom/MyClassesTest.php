<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kelas saya (AC-3d, PRD-002 §16, OD-AC-06).
 *
 * Akun `guru` (penugasan MA Unit 1 & MDA Unit 1) dijadikan pegawai yang ditempatkan di MA Unit 1.
 * Tahun berjalan, MA Unit 1: X-A (wali kelas: guru) berisi Ahmad (kontak utama: ibu Siti) dan Aisyah;
 * XI-A berisi Citra (guru mengampu Fiqih, ada jadwal Senin 07.00–08.00); XII-A (tidak ia pegang).
 * MDA Unit 1: Ula 1 dengan wali kelas guru — tidak tampil karena guru tidak ditempatkan di MDA.
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
    $this->mdaU1 = CoreFixtures::node($this->tenant, 'U1-MDA')->id;
    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $this->employee = ksyEmployee($this->tenant, $membership->id, 'U1-MA');

    // Hanya pegawai aktif yang ditempatkan di lembaga itu yang boleh jadi wali kelas: Ula 1 diisi langsung.
    foreach ([['X-A', 10, $this->ma, $this->employee->id], ['XI-A', 11, $this->ma, null], ['XII-A', 12, $this->ma, null], ['Ula 1', 1, $this->mdaU1, null]] as [$name, $grade, $organization, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $this->current->id, 'grade' => $grade, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'Ula 1')->update(['homeroom_employee_id' => $this->employee->id]);

    foreach ([
        ['Ahmad Fauzi', 'L', [['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222']]],
        ['Aisyah Putri', 'P', []],
        ['Citra Lestari', 'P', []],
    ] as [$name, $gender, $guardians]) {
        $values = [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
            'class_id' => ksyClass($name === 'Citra Lestari' ? 'XI-A' : 'X-A')->id,
        ];

        if ($guardians !== []) {
            $values = array_merge($values, ['nisn' => '0012345678', 'guardians' => $guardians, 'primary_guardian' => 0]);
        }

        $this->actingAs($this->admin)->post(route('academic.students.store'), $values)->assertSessionHasNoErrors();
    }

    // XI-A: guru mengampu Fiqih, dengan jadwal.
    $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => 'Fiqih'])->assertSessionHasNoErrors();
    $fiqih = (string) DB::table('ac_subjects')->where('organization_id', $this->ma)->where('name', 'Fiqih')->value('id');
    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => ksyClass('XI-A')->id]), ['subject_ids' => [$fiqih]])->assertSessionHasNoErrors();
    $classSubject = (string) DB::table('ac_class_subjects')->where('class_id', ksyClass('XI-A')->id)->value('id');
    $this->actingAs($this->admin)->patch(route('academic.class-subjects.update', ['classSubject' => $classSubject]), ['teacher_employee_id' => $this->employee->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.schedule.store', ['class' => ksyClass('XI-A')->id]), [
        'class_subject_id' => $classSubject, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
    ])->assertSessionHasNoErrors();

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
});

function ksyEmployee(Tenant $tenant, string $membershipId, string $organizationCode): Employee
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: $membershipId,
        name: 'Guru MA & MDA',
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

function ksyClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

test('Kelas saya: kelas sebagai wali kelas & pengampu di lembaga tempat ia aktif, beserta jadwal mengajar; menu muncul', function () {
    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-classes/index')
            ->where('academic.myClasses', true)
            ->where('linked', true)
            ->has('classes', 2)
            ->where('classes.0.name', 'X-A')
            ->where('classes.0.homeroom', true)
            ->where('classes.0.subjects', [])
            ->where('classes.1.name', 'XI-A')
            ->where('classes.1.homeroom', false)
            ->where('classes.1.subjects', ['Fiqih'])
            ->has('schedule', 1)
            ->where('schedule.0.day', 1)
            ->where('schedule.0.start', '07:00')
            ->where('schedule.0.class_name', 'XI-A'),
        );
});

test('wali kelas melihat siswa kelasnya beserta kontak utama orang tua; data pribadi lain tidak dikirim', function () {
    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.show', ['class' => ksyClass('X-A')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-classes/show')
            ->has('students', 2)
            ->where('students.0.name', 'Ahmad Fauzi')
            ->where('students.0.nisn', '0012345678')
            ->where('students.0.contact', ['name' => 'Siti Aminah', 'relation' => 'Ibu', 'phone' => '081311112222'])
            ->where('students.1.name', 'Aisyah Putri')
            ->where('students.1.contact', null)
            ->missing('students.0.birth_date')
            ->missing('students.0.address'),
        );
});

test('guru pengampu (bukan wali kelas) tidak melihat kontak orang tua', function () {
    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.show', ['class' => ksyClass('XI-A')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('class.homeroom', false)
            ->has('students', 1)
            ->where('students.0.name', 'Citra Lestari')
            ->where('students.0.contact', null),
        );
});

test('kelas yang tidak ia pegang, di lembaga tempat ia tidak aktif, atau ID asal-asalan: 404', function () {
    foreach (['XII-A', 'Ula 1'] as $class) {
        $this->actingAs($this->guru)->get(route('academic.my-classes.show', ['class' => ksyClass($class)->id]))->assertNotFound();
    }

    $this->actingAs($this->guru)->get(route('academic.my-classes.show', ['class' => (string) Str::uuid7()]))->assertNotFound();

    // Hak ini tidak membuka menu Kelas biasa.
    $this->actingAs($this->guru)->get(route('academic.classes.show', ['class' => ksyClass('X-A')->id]))->assertForbidden();
});

test('berhenti sebagai pegawai atau bukan pegawai: kelasnya tidak tampil lagi', function () {
    DB::table('hr_employees')->where('id', $this->employee->id)->update(['status' => 'BERHENTI']);

    $this->actingAs($this->guru)
        ->get(route('academic.my-classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('academic.myClasses', false)->where('linked', true)->where('classes', []));
    $this->actingAs($this->guru)->get(route('academic.my-classes.show', ['class' => ksyClass('X-A')->id]))->assertNotFound();

    // Operator (bukan pegawai): halaman memberi tahu akun belum tertaut.
    $this->flushSession();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->get(route('academic.my-classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('linked', false)->where('classes', [])->where('academic.myClasses', false));
});
