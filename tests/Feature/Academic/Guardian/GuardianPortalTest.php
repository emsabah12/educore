<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Guardian\GuardianAccounts;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Akun orang tua/wali & portal Anak saya (PRD-002 §21, AC-7a).
 *
 * SMP Unit 3 (lembaga operator.smp), tahun berjalan: kelas 7-A (wali kelas Bu Rina) berisi Ahmad;
 * jadwal Fiqih Senin 07.00–08.00 diampu Bu Rina.
 *   Ahmad  — ibu Siti Aminah (HP 081311112222, kontak utama)
 *   Aisyah — adik Ahmad, ibu yang sama
 *   Citra  — ayah Joko (tanpa HP)
 *   Budi   — MTs Unit 1 (di luar lembaga operator), ibu Siti juga
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
    $current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $rina = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: null, name: 'Bu Rina', gender: Gender::Female, birthPlace: null, birthDate: null,
        phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: $this->smp, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->smp, 'academic_year_id' => $current->id, 'grade' => 7, 'name' => '7-A', 'homeroom_employee_id' => $rina->id,
    ])->assertSessionHasNoErrors();
    $class = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', '7-A')->sole();

    $ibu = ['guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222']], 'primary_guardian' => 0];

    foreach ([
        ['Ahmad Fauzi', $this->smp, $ibu + ['class_id' => $class->id, 'nisn' => '0012345678']],
        ['Aisyah Fauzi', $this->smp, $ibu],
        ['Citra Lestari', $this->smp, ['guardians' => [['relation' => 'AYAH', 'name' => 'Joko']], 'primary_guardian' => 0]],
        ['Budi Santoso', CoreFixtures::node($this->tenant, 'U1-MTS')->id, $ibu],
    ] as [$name, $organization, $extra]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), array_merge([
            'name' => $name, 'gender' => 'L', 'organization_id' => $organization, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ], $extra))->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->smp, 'name' => 'Fiqih'])->assertSessionHasNoErrors();
    $fiqih = (string) DB::table('ac_subjects')->where('organization_id', $this->smp)->where('name', 'Fiqih')->value('id');
    $this->post(route('academic.class-subjects.store', ['class' => $class->id]), ['subject_ids' => [$fiqih]])->assertSessionHasNoErrors();
    $classSubject = (string) DB::table('ac_class_subjects')->where('class_id', $class->id)->value('id');
    $this->patch(route('academic.class-subjects.update', ['classSubject' => $classSubject]), ['teacher_employee_id' => $rina->id])->assertSessionHasNoErrors();
    $this->post(route('academic.schedule.store', ['class' => $class->id]), [
        'class_subject_id' => $classSubject, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
    ])->assertSessionHasNoErrors();

    $this->ahmad = aotStudent($this->tenant, 'Ahmad Fauzi');
    $this->aisyah = aotStudent($this->tenant, 'Aisyah Fauzi');
    $this->citra = aotStudent($this->tenant, 'Citra Lestari');
    $this->budi = aotStudent($this->tenant, 'Budi Santoso');
    $this->flushSession();
});

function aotMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();
}

function aotStudent(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', aotMembership($tenant, $name)->id)->sole();
}

function aotLink(Tenant $tenant, Student $student, string $name): string
{
    return (string) StudentGuardian::query()->withoutGlobalScope('tenant')
        ->where('student_id', $student->id)
        ->where('guardian_membership_id', aotMembership($tenant, $name)->id)
        ->value('id');
}

/**
 * @return array{student: string, guardian: string}
 */
function aotParams(Tenant $tenant, Student $student, string $name): array
{
    return ['student' => $student->id, 'guardian' => aotLink($tenant, $student, $name)];
}

test('operator membuat akun orang tua: username = HP, sandi sementara tampil sekali, wajib ganti; atur ulang, nonaktif, aktif', function () {
    $params = aotParams($this->tenant, $this->ahmad, 'Siti Aminah');
    $this->actingAs($this->operator);

    $this->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where("manage.guardianAccounts.{$params['guardian']}.status", 'NONE')
            ->where("manage.guardianAccounts.{$params['guardian']}.manageable", true));

    $this->post(route('academic.guardian-accounts.store', $params))->assertSessionHasNoErrors();

    $user = User::query()->where('username', '081311112222')->sole();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->status)->toBe(UserStatus::Active);

    $this->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where("manage.guardianAccounts.{$params['guardian']}.status", 'ACTIVE')
            ->where("manage.guardianAccounts.{$params['guardian']}.login", '081311112222')
            ->where("manage.guardianAccounts.{$params['guardian']}.must_change", true));

    // Dari adiknya (Aisyah) orang yang sama sudah punya akun.
    $this->post(route('academic.guardian-accounts.store', aotParams($this->tenant, $this->aisyah, 'Siti Aminah')))
        ->assertSessionHasErrors(['account' => 'Orang tua/wali ini sudah punya akun login.']);

    $oldHash = $user->password;
    $this->post(route('academic.guardian-accounts.reset', $params))->assertSessionHasNoErrors();
    expect($user->refresh()->password)->not->toBe($oldHash);

    $issued = app(GuardianAccounts::class)->resetPassword($this->tenant->id, $this->ahmad->id, $params['guardian'], null);
    expect($issued->login)->toBe('081311112222')
        ->and(Hash::check($issued->password, $user->refresh()->password))->toBeTrue();

    $this->post(route('academic.guardian-accounts.deactivate', $params))->assertSessionHasNoErrors();
    expect($user->refresh()->status)->toBe(UserStatus::Inactive);
    $this->post(route('academic.guardian-accounts.activate', $params))->assertSessionHasNoErrors();
    expect($user->refresh()->status)->toBe(UserStatus::Active);

    // Kata sandi tidak pernah masuk audit.
    expect(DB::table('audit_logs')->pluck('changes')->implode(' '))->not->toContain($issued->password);
});

test('HP kosong ditolak; orang yang juga punya peran lain tidak diatur dari halaman siswa', function () {
    $this->actingAs($this->operator);

    $this->post(route('academic.guardian-accounts.store', aotParams($this->tenant, $this->citra, 'Joko')))
        ->assertSessionHasErrors(['account' => 'Isi dulu nomor HP orang tua/wali ini; nomor HP dipakai sebagai username.']);

    // Siti mendapat penugasan di yayasan → bukan murni orang tua/wali lagi.
    DB::table('organizational_assignments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'membership_id' => aotMembership($this->tenant, 'Siti Aminah')->id,
        'organization_id' => $this->smp, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $params = aotParams($this->tenant, $this->ahmad, 'Siti Aminah');

    $this->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->where("manage.guardianAccounts.{$params['guardian']}.manageable", false));
    $this->post(route('academic.guardian-accounts.store', $params))->assertSessionHasErrors('account');
    expect(User::query()->where('username', '081311112222')->exists())->toBeFalse();
});

test('hak akses: siswa di luar cakupan 404, tanpa izin kelola 403', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.guardian-accounts.store', aotParams($this->tenant, $this->budi, 'Siti Aminah')))
        ->assertNotFound();

    $this->flushSession();
    $kepala = DevAccounts::user('pimpinan.ponpes');
    $this->actingAs($kepala)
        ->post(route('academic.guardian-accounts.store', aotParams($this->tenant, $this->ahmad, 'Siti Aminah')))
        ->assertForbidden();
});

test('Anak saya: daftar anak, profil, kelas & wali kelas, jadwal, kalender; anak orang lain 404; tautan dilepas → akses hilang', function () {
    $this->actingAs($this->operator)->post(route('academic.guardian-accounts.store', aotParams($this->tenant, $this->ahmad, 'Siti Aminah')))->assertSessionHasNoErrors();
    $this->flushSession();
    $siti = User::query()->where('username', '081311112222')->sole();
    $siti->forceFill(['must_change_password' => false])->save();

    $this->actingAs($siti)->get(route('academic.my-children.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-children/index')
            ->where('academic.isGuardian', true)
            ->has('children', 3)
            ->where('children.0.name', 'Ahmad Fauzi')
            ->where('children.0.relation', 'Ibu')
            ->where('children.0.classes', ['7-A — SMP Unit 3'])
            ->where('children.2.name', 'Budi Santoso'));

    $this->get(route('academic.my-children.show', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/my-children/show')
            ->where('student.name', 'Ahmad Fauzi')
            ->where('student.nisn', '0012345678')
            ->missing('student.nik')
            ->where('enrollments.0.organization', 'SMP Unit 3')
            ->where('classes.0.name', '7-A')
            ->where('classes.0.homeroom', 'Bu Rina')
            ->where('classes.0.schedule', [['day' => 1, 'start' => '07:00', 'end' => '08:00', 'subject' => 'Fiqih', 'teacher' => 'Bu Rina']])
            ->where('calendars.0.organization', 'SMP Unit 3')
            ->whereNot('calendars.0.year', null)
            ->has('siblings', 3));

    $this->get(route('academic.my-children.show', ['student' => $this->citra->id]))->assertNotFound();

    // Lepas tautan Siti dari Aisyah (kontak utama → tidak ada pengganti, Aisyah tanpa orang tua/wali lain).
    StudentGuardian::query()->withoutGlobalScope('tenant')->whereKey(aotLink($this->tenant, $this->aisyah, 'Siti Aminah'))->delete();
    $this->get(route('academic.my-children.show', ['student' => $this->aisyah->id]))->assertNotFound();
});

test('bukan orang tua/wali: menu tidak muncul, daftar kosong', function () {
    $this->actingAs($this->admin)->get(route('academic.my-children.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('academic.isGuardian', false)->where('children', []));
});
