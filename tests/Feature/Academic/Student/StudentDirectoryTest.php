<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Daftar & detail siswa (AC-1c-a, PRD-002 §5.3, §6): siapa melihat siapa, saringan, data pribadi.
 *
 * Data uji:
 *   Ahmad — SMP Unit 3 + MDA Unit 3 (aktif keduanya), dengan data pribadi & ibu
 *   Budi  — MTs Unit 1 (aktif)
 *   Citra — MDA Unit 2 (sudah lulus)
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $node = fn (string $code): string => CoreFixtures::node($this->tenant, $code)->id;

    $this->actingAs($this->admin)->post(route('academic.students.store'), dirIsian('Ahmad Fauzi', $node('U3-SMP'), [
        'nisn' => '0012345678',
        'student_number' => 'SMP-001',
        'birth_place' => 'Bandung',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), dirIsian('Ahmad Fauzi', $node('U3-MDA'), [
        'existing_membership_id' => dirMembership($this->tenant, 'Ahmad Fauzi')->id,
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), dirIsian('Budi Santoso', $node('U1-MTS')))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), dirIsian('Citra Lestari', $node('U2-MDA'), ['gender' => 'P']))->assertSessionHasNoErrors();

    // Citra lulus dari MDA Unit 2.
    DB::table('ac_enrollments')
        ->where('student_id', dirStudent($this->tenant, 'Citra Lestari')->id)
        ->update(['end_date' => '2026-06-15', 'end_reason' => 'LULUS']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dirIsian(string $name, string $organizationId, array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'gender' => 'L',
        'organization_id' => $organizationId,
        'start_date' => '2025-07-14',
        'entry_type' => 'BARU',
    ], $overrides);
}

function dirMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()
        ->where('tenant_id', $tenant->id)
        ->whereHas('person', fn ($person) => $person->where('name', $name))
        ->sole();
}

function dirStudent(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', dirMembership($tenant, $name)->id)->sole();
}

/**
 * Nama siswa di halaman daftar, sesuai urutan tampil.
 *
 * @param  array<string, string>  $query
 * @return list<string>
 */
function dirNames(TestCase $test, User $user, array $query = []): array
{
    $response = $test->actingAs($user)->get(route('academic.students.index', $query))->assertOk();

    /** @var array{props: array{students: array{data: list<array{name: string}>}}} $page */
    $page = $response->viewData('page');

    return array_column($page['props']['students']['data'], 'name');
}

// ── Siapa melihat siapa ───────────────────────────────────────────────────────

test('Admin Yayasan melihat semua siswa aktif; yang lulus/keluar hanya bila diminta', function () {
    expect(dirNames($this, $this->admin))->toBe(['Ahmad Fauzi', 'Budi Santoso'])
        ->and(dirNames($this, $this->admin, ['status' => 'keluar']))->toBe(['Citra Lestari'])
        ->and(dirNames($this, $this->admin, ['status' => 'semua']))->toBe(['Ahmad Fauzi', 'Budi Santoso', 'Citra Lestari']);

    $this->actingAs($this->admin)
        ->get(route('academic.students.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('students.total', 2)
            ->where('students.data.0.status', 'AKTIF')
            ->has('students.data.0.enrollments', 2)
            ->where('students.data.0.enrollments.0.organization', 'MDA Unit 3')
            ->where('students.data.0.other_active', 0)
            ->where('canCreate', true),
        );
});

test('Operator SMP Unit 3 hanya melihat siswa SMP-nya, tetapi detail memuat pendaftaran di MDA juga', function () {
    $operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $ahmad = dirStudent($this->tenant, 'Ahmad Fauzi');

    expect(dirNames($this, $operator))->toBe(['Ahmad Fauzi']);

    $this->actingAs($operator)
        ->get(route('academic.students.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('students.data.0.enrollments', 1)
            ->where('students.data.0.enrollments.0.organization', 'SMP Unit 3')
            ->where('students.data.0.enrollments.0.student_number', 'SMP-001')
            ->where('students.data.0.other_active', 1)
            ->has('options.organizations', 1),
        );

    $this->actingAs($operator)
        ->get(route('academic.students.show', ['student' => $ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/students/show')
            ->where('student.name', 'Ahmad Fauzi')
            ->where('student.nisn', '0012345678')
            ->where('student.active', true)
            ->has('student.enrollments.current', 2)
            ->where('student.personal.birth_place', 'Bandung')
            ->has('student.guardians', 1)
            ->where('student.guardians.0.name', 'Siti Aminah')
            ->where('student.guardians.0.is_primary', true),
        );

    // Siswa MTs Unit 1 di luar cakupannya.
    $this->actingAs($operator)
        ->get(route('academic.students.show', ['student' => dirStudent($this->tenant, 'Budi Santoso')->id]))
        ->assertNotFound();
});

test('Kepala Unit 1 melihat siswa di Unit 1 saja; siswa Unit 3 dijawab 404', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    expect(dirNames($this, $kepala))->toBe(['Budi Santoso'])
        ->and(dirNames($this, $kepala, ['status' => 'semua']))->toBe(['Budi Santoso']);

    $this->actingAs($kepala)
        ->get(route('academic.students.show', ['student' => dirStudent($this->tenant, 'Ahmad Fauzi')->id]))
        ->assertNotFound();
    $this->actingAs($kepala)
        ->get(route('academic.students.show', ['student' => dirStudent($this->tenant, 'Budi Santoso')->id]))
        ->assertOk();
});

test('Koordinator MDA melihat santri semua MDA termasuk alumni, tanpa data pribadi & orang tua', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    expect(dirNames($this, $koordinator))->toBe(['Ahmad Fauzi'])
        ->and(dirNames($this, $koordinator, ['status' => 'keluar']))->toBe(['Citra Lestari']);

    $this->actingAs($koordinator)
        ->get(route('academic.students.show', ['student' => dirStudent($this->tenant, 'Ahmad Fauzi')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.personal', null)
            ->where('student.guardians', null)
            ->has('student.enrollments.current', 2)
            ->where('canCreate', false),
        );

    // Alumni MDA Unit 2: detail tetap terbuka dengan riwayat pendaftarannya.
    $this->actingAs($koordinator)
        ->get(route('academic.students.show', ['student' => dirStudent($this->tenant, 'Citra Lestari')->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.active', false)
            ->has('student.enrollments.current', 0)
            ->where('student.enrollments.history.0.end_reason', 'Lulus'),
        );
});

test('lulus di satu lembaga tetapi masih aktif di lembaga lain: lembaga lama tetap melihatnya sebagai alumni', function () {
    $ahmad = dirStudent($this->tenant, 'Ahmad Fauzi');
    $mda = CoreFixtures::node($this->tenant, 'U3-MDA')->id;

    DB::table('ac_enrollments')->where('student_id', $ahmad->id)->where('organization_id', $mda)
        ->update(['end_date' => '2026-06-15', 'end_reason' => 'LULUS']);

    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    expect(dirNames($this, $koordinator))->toBe([])
        ->and(dirNames($this, $koordinator, ['status' => 'keluar']))->toBe(['Ahmad Fauzi', 'Citra Lestari'])
        // Bagi Admin Yayasan, Ahmad masih aktif (SMP).
        ->and(dirNames($this, $this->admin))->toBe(['Ahmad Fauzi', 'Budi Santoso']);
});

// ── Pencarian & saringan ──────────────────────────────────────────────────────

test('pencarian nama, NISN, nomor induk dan saringan lembaga ikut turunannya', function () {
    $node = fn (string $code): string => CoreFixtures::node($this->tenant, $code)->id;

    expect(dirNames($this, $this->admin, ['q' => 'ahmad']))->toBe(['Ahmad Fauzi'])
        ->and(dirNames($this, $this->admin, ['q' => '0012345678']))->toBe(['Ahmad Fauzi'])
        ->and(dirNames($this, $this->admin, ['q' => 'smp-001']))->toBe(['Ahmad Fauzi'])
        ->and(dirNames($this, $this->admin, ['q' => '100%']))->toBe([])
        ->and(dirNames($this, $this->admin, ['lembaga' => $node('PONPES-U3')]))->toBe(['Ahmad Fauzi'])
        ->and(dirNames($this, $this->admin, ['lembaga' => $node('U2-MDA'), 'status' => 'semua']))->toBe(['Citra Lestari'])
        // Nilai saringan yang tidak sah diabaikan.
        ->and(dirNames($this, $this->admin, ['lembaga' => 'bukan-uuid', 'status' => 'aneh', 'per_page' => '500']))->toBe(['Ahmad Fauzi', 'Budi Santoso']);
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('tamu diarahkan ke login; tanpa izin lihat siswa: 403; siswa yayasan lain atau ID asal-asalan: 404', function () {
    // beforeEach sudah login sebagai admin (actingAs berlaku untuk request berikutnya): lupakan dulu.
    $this->app['auth']->forgetGuards();
    $this->get(route('academic.students.index'))->assertRedirect(route('login'));

    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('academic.students.index'))->assertForbidden();

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $siswaLain = Student::query()->create([
        'tenant_id' => $tenantLain->id,
        'membership_id' => CoreFixtures::member(User::factory()->create(), $tenantLain)->id,
    ]);

    $this->actingAs($this->admin)->get(route('academic.students.show', ['student' => $siswaLain->id]))->assertNotFound();
    $this->actingAs($this->admin)->get(route('academic.students.show', ['student' => '01900000-0000-7000-8000-000000000000']))->assertNotFound();
});
