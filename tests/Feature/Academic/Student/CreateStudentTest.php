<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Student;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Tambah siswa (AC-1b, PRD-002 §4.3, §4.5, §6): orang baru / orang yang sama, pendaftaran, orang tua/wali.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mda = CoreFixtures::node($this->tenant, 'U3-MDA');
});

/**
 * Isian form tambah siswa yang sah.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sisIsian(string $organizationId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Ahmad Fauzi',
        'gender' => 'L',
        'organization_id' => $organizationId,
        'start_date' => '2026-07-13',
        'entry_type' => 'BARU',
    ], $overrides);
}

function sisMembershipOf(Tenant $tenant, string $name): Membership
{
    return Membership::query()
        ->withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->whereHas('person', fn ($person) => $person->where('name', $name))
        ->sole();
}

function sisStudentOf(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', sisMembershipOf($tenant, $name)->id)->sole();
}

function sisCount(string $table): int
{
    return DB::table($table)->count();
}

// ── Halaman & hak akses ───────────────────────────────────────────────────────

test('Operator Akademik membuka form; pilihan lembaga hanya lembaga yang boleh ia kelola', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/students/create')
            ->has('options.organizations', 1)
            ->where('options.organizations.0.value', $this->smp->id)
            ->has('options.relations', 4)
            ->has('candidates', 0)
            ->where('permissions', fn ($permissions) => collect($permissions)->contains(AcademicAccess::STUDENTS_MANAGE)),
        );

    // Admin Yayasan: semua lembaga aktif (16 di pohon tenant pertama).
    $this->actingAs($this->admin)
        ->get(route('academic.students.create'))
        ->assertInertia(fn (Assert $page) => $page->has('options.organizations', 16));
});

test('tamu diarahkan ke login; Kepala Lembaga (hanya melihat) ditolak 403', function () {
    $this->get(route('academic.students.create'))->assertRedirect(route('login'));

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('academic.students.create'))->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.students.store'), sisIsian(CoreFixtures::node($this->tenant, 'U1-MTS')->id))
        ->assertForbidden();

    expect(sisCount('ac_students'))->toBe(0);
});

test('operator tidak bisa mendaftarkan ke lembaga di luar cakupannya atau milik yayasan lain: 404', function () {
    $this->actingAs($this->operator)->post(route('academic.students.store'), sisIsian($this->mda->id))->assertNotFound();

    $lembagaLain = CoreFixtures::unit(CoreFixtures::tenant('YYS-LAIN'), 'UNIT-LAIN');
    $this->actingAs($this->operator)->post(route('academic.students.store'), sisIsian($lembagaLain->id))->assertNotFound();

    // Unit bukan lembaga.
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian(CoreFixtures::node($this->tenant, 'PONPES-U3')->id))
        ->assertSessionHasErrors(['organization_id' => 'Siswa hanya bisa didaftarkan ke lembaga (bukan unit atau biro).']);

    expect(sisCount('ac_students'))->toBe(0)->and(sisCount('ac_enrollments'))->toBe(0);
});

// ── Orang baru ────────────────────────────────────────────────────────────────

test('siswa baru: orang, keanggotaan, siswa, pendaftaran, dan orang tua tersimpan sekali simpan', function () {
    $usersBefore = User::query()->count();

    $response = $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, [
            'birth_place' => 'Bandung',
            'birth_date' => '2013-04-12',
            'phone' => '0812-3456-7890',
            'address' => 'Jl. Melati 1',
            'nisn' => '0012 3456 78',
            'student_number' => 'nis/2026.001',
            'entry_type' => 'PINDAHAN',
            'guardians' => [
                ['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222'],
                ['relation' => 'AYAH', 'name' => 'Budi Santoso', 'gender' => '', 'phone' => ''],
            ],
            'primary_guardian' => 0,
        ]))
        ->assertSessionHasNoErrors();

    $student = sisStudentOf($this->tenant, 'Ahmad Fauzi');

    // Setelah tersimpan langsung ke detail siswa (AC-1c-a).
    $response->assertRedirect(route('academic.students.show', ['student' => $student->id]));
    $person = $student->membership->person;
    $enrollment = Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $student->id)->sole();

    expect($student->nisn)->toBe('0012345678')
        ->and($person->phone)->toBe('081234567890')
        ->and($person->birth_date?->toDateString())->toBe('2013-04-12')
        ->and($enrollment->organization_id)->toBe($this->smp->id)
        ->and($enrollment->student_number)->toBe('NIS/2026.001')
        ->and($enrollment->entry_type->value)->toBe('PINDAHAN')
        ->and($enrollment->start_date->toDateString())->toBe('2026-07-13')
        ->and($enrollment->end_date)->toBeNull()
        // Tidak ada akun login untuk siswa maupun orang tua.
        ->and(User::query()->count())->toBe($usersBefore);

    $guardians = StudentGuardian::query()->withoutGlobalScope('tenant')->where('student_id', $student->id)->get();
    $ibu = $guardians->firstWhere('relation.value', 'IBU');

    expect($guardians)->toHaveCount(2)
        ->and($ibu?->is_primary_contact)->toBeTrue()
        ->and($guardians->where('is_primary_contact', true))->toHaveCount(1)
        ->and(sisMembershipOf($this->tenant, 'Siti Aminah')->person->phone)->toBe('081311112222');

    // Audit: nama field data pribadi saja, tanpa nilainya.
    $created = DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENT_CREATED)->sole();

    expect((string) $created->changes)->not->toContain('Bandung')
        ->and((string) $created->changes)->not->toContain('Melati')
        ->and(json_decode((string) $created->changes, true)['data_pribadi_diisi'])->toEqualCanonicalizing(['birth_place', 'birth_date', 'phone', 'address'])
        ->and($created->organization_id)->toBe($this->smp->id)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::ENROLLMENT_CREATED)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::GUARDIAN_LINKED)->count())->toBe(2)
        ->and(AuditAction::label(AcademicAuditAction::STUDENT_CREATED))->toBe('Siswa ditambahkan');
});

test('bila satu langkah gagal, tidak ada yang tersimpan, termasuk orang dan keanggotaan baru', function () {
    $persons = sisCount('persons');
    $memberships = sisCount('memberships');

    // Orang tua yang sama ditulis dua kali: baru ketahuan setelah siswa & orang tua pertama dibuat.
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, [
            'guardians' => [
                ['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222'],
                ['relation' => 'WALI', 'name' => 'siti  aminah', 'phone' => '0813-1111-2222'],
            ],
            'primary_guardian' => 0,
        ]))
        ->assertSessionHasErrors(['guardians.1.name' => 'Orang tua/wali ini tercatat dua kali.']);

    expect(sisCount('persons'))->toBe($persons)
        ->and(sisCount('memberships'))->toBe($memberships)
        ->and(sisCount('ac_students'))->toBe(0)
        ->and(sisCount('ac_enrollments'))->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'like', 'academic.student%')->count())->toBe(0);
});

test('isian yang salah ditolak dengan pesan di isian terkait', function (string $case) {
    [$overrides, $field] = match ($case) {
        'nama kosong' => [['name' => '   '], 'name'],
        'jenis kelamin kosong' => [['gender' => ''], 'gender'],
        'NISN 9 angka' => [['nisn' => '123456789'], 'nisn'],
        'nomor HP huruf' => [['phone' => '0812abc'], 'phone'],
        'tanggal lahir masa depan' => [['birth_date' => '2999-01-01'], 'birth_date'],
        'tanggal masuk masa depan' => [['start_date' => '2999-01-01'], 'start_date'],
        'tanggal masuk kosong' => [['start_date' => ''], 'start_date'],
        'nomor induk berspasi' => [['student_number' => 'NIS 01'], 'student_number'],
        'jenis masuk salah' => [['entry_type' => 'MUTASI'], 'entry_type'],
        'lembaga kosong' => [['organization_id' => ''], 'organization_id'],
        'nama orang tua kosong' => [['guardians' => [['relation' => 'IBU', 'name' => '']], 'primary_guardian' => 0], 'guardians.0.name'],
        'hubungan salah' => [['guardians' => [['relation' => 'KAKAK', 'name' => 'Rina']], 'primary_guardian' => 0], 'guardians.0.relation'],
        'lima orang tua' => [['guardians' => array_fill(0, 5, ['relation' => 'WALI', 'name' => 'Wali']), 'primary_guardian' => 0], 'guardians'],
        'kontak utama di luar daftar' => [['guardians' => [['relation' => 'IBU', 'name' => 'Rina']], 'primary_guardian' => 3], 'primary_guardian'],
    };

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, $overrides))
        ->assertSessionHasErrors($field);

    expect(sisCount('ac_students'))->toBe(0);
})->with([
    'nama kosong',
    'jenis kelamin kosong',
    'NISN 9 angka',
    'nomor HP huruf',
    'tanggal lahir masa depan',
    'tanggal masuk masa depan',
    'tanggal masuk kosong',
    'nomor induk berspasi',
    'jenis masuk salah',
    'lembaga kosong',
    'nama orang tua kosong',
    'hubungan salah',
    'lima orang tua',
    'kontak utama di luar daftar',
]);

test('NISN tidak boleh dipakai dua siswa; nomor induk tidak boleh dipakai siswa lain di lembaga yang sama', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->smp->id, [
        'nisn' => '0012345678',
        'student_number' => 'NIS-001',
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['name' => 'Budi', 'nisn' => '0012345678']))
        ->assertSessionHasErrors(['nisn' => 'NISN ini sudah dipakai siswa lain di yayasan ini.']);
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['name' => 'Budi', 'student_number' => 'nis-001']))
        ->assertSessionHasErrors(['student_number' => 'Nomor induk NIS-001 sudah dipakai siswa lain di lembaga ini.']);

    // Nomor induk yang sama di lembaga lain: boleh.
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian($this->mda->id, ['name' => 'Budi', 'student_number' => 'NIS-001']))
        ->assertSessionHasNoErrors();

    expect(sisCount('ac_students'))->toBe(2);
});

// ── Orang yang sama (usulan A) ────────────────────────────────────────────────

test('nama sama memunculkan calon; memilih "orang yang sama" hanya menambah pendaftaran (SMP + MDA)', function () {
    // Santri MDA Unit 3 yang sudah tercatat.
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->mda->id, [
        'birth_date' => '2013-04-12',
        'nisn' => '0012345678',
    ]))->assertSessionHasNoErrors();
    $santri = sisMembershipOf($this->tenant, 'Ahmad Fauzi');
    $persons = sisCount('persons');

    // Operator SMP mengetik nama yang sama (beda huruf besar & spasi).
    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['name' => 'ahmad   FAUZI']))
        ->assertSessionHasErrors('duplicate');

    $this->actingAs($this->operator)
        ->get(route('academic.students.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates', 1)
            ->where('candidates.0.membership_id', $santri->id)
            ->where('candidates.0.label', 'Ahmad Fauzi — siswa MDA Unit 3, lahir 2013-04-12'),
        );

    // Memilih orang yang sama: tanggal lahir lama TIDAK ditimpa, alamat yang kosong dilengkapi.
    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, [
            'name' => 'Ahmad Fauzi',
            'birth_date' => '2013-12-04',
            'address' => 'Jl. Kenanga 2',
            'existing_membership_id' => $santri->id,
        ]))
        ->assertSessionHasNoErrors();

    $student = sisStudentOf($this->tenant, 'Ahmad Fauzi');
    $person = $student->membership->person;

    expect(sisCount('persons'))->toBe($persons)
        ->and(sisCount('ac_students'))->toBe(1)
        ->and(Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $student->id)->whereNull('end_date')->pluck('organization_id')->all())
        ->toEqualCanonicalizing([$this->mda->id, $this->smp->id])
        ->and($person->birth_date?->toDateString())->toBe('2013-04-12')
        ->and($person->address)->toBe('Jl. Kenanga 2')
        ->and($student->nisn)->toBe('0012345678')
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENT_CREATED)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::ENROLLMENT_CREATED)->count())->toBe(2);

    // Setelah berhasil, calon tidak tampil lagi.
    $this->actingAs($this->operator)
        ->get(route('academic.students.create'))
        ->assertInertia(fn (Assert $page) => $page->has('candidates', 0));
});

test('semua masalah tampil sekaligus di isiannya: nama sama + NISN milik orang itu + nomor induk terpakai', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->smp->id, [
        'nisn' => '0012345678',
        'student_number' => 'NIS-001',
    ]))->assertSessionHasNoErrors();
    $persons = sisCount('persons');

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, [
            'gender' => '',
            'nisn' => '0012345678',
            'student_number' => 'NIS-001',
        ]))
        ->assertSessionHasErrors([
            'duplicate' => 'Nama "Ahmad Fauzi" sama dengan anggota yayasan di bawah ini. Bila orangnya sama, pilih orang tersebut agar hanya pendaftarannya yang ditambahkan. Bila memang orang berbeda, pilih "Orang berbeda", lalu simpan ulang.',
            'nisn' => 'NISN ini sudah tercatat untuk salah satu orang di kotak peringatan (nama sama). Bila orangnya sama, pilih orang tersebut; bila berbeda, periksa lagi NISN-nya.',
            'student_number' => 'Nomor induk NIS-001 sudah dipakai siswa lain di lembaga ini.',
            'gender' => 'Jenis kelamin wajib dipilih.',
        ]);

    expect(sisCount('persons'))->toBe($persons);
});

test('"orang berbeda" dengan NISN yang sudah dipakai: hanya isian NISN yang ditandai', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->smp->id, ['nisn' => '0012345678']));

    $response = $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['nisn' => '0012345678', 'confirm_new' => '1']));

    $response->assertSessionHasErrors(['nisn' => 'NISN ini sudah dipakai siswa lain di yayasan ini.'])
        ->assertSessionDoesntHaveErrors('duplicate');

    expect(sisCount('ac_students'))->toBe(1);
});

test('memilih "orang berbeda" membuat orang baru walau namanya sama', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->mda->id));
    $persons = sisCount('persons');

    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['confirm_new' => '1']))
        ->assertSessionHasNoErrors();

    expect(sisCount('persons'))->toBe($persons + 1)
        ->and(sisCount('ac_students'))->toBe(2);
});

test('pilihan orang yang tidak cocok, NISN berbeda, atau sudah terdaftar di lembaga itu ditolak', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->smp->id, ['nisn' => '0012345678']));
    $ahmad = sisMembershipOf($this->tenant, 'Ahmad Fauzi');

    // ID orang lain (nama berbeda) atau ID asal-asalan tidak dipercaya.
    $lain = CoreFixtures::member(User::factory()->create(), $this->tenant);
    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['existing_membership_id' => $lain->id]))
        ->assertSessionHasErrors('existing_membership_id');

    // NISN berbeda dari yang tercatat.
    $this->actingAs($this->admin)
        ->post(route('academic.students.store'), sisIsian($this->mda->id, ['existing_membership_id' => $ahmad->id, 'nisn' => '9999999999']))
        ->assertSessionHasErrors(['nisn' => 'NISN berbeda dengan yang sudah tercatat untuk siswa ini. Kosongkan bila tidak ingin mengubahnya.']);

    // Sudah terdaftar aktif di SMP.
    $this->actingAs($this->operator)
        ->post(route('academic.students.store'), sisIsian($this->smp->id, ['existing_membership_id' => $ahmad->id]))
        ->assertSessionHasErrors(['organization_id' => 'Orang ini sudah terdaftar aktif di lembaga tersebut.']);

    expect(sisCount('ac_enrollments'))->toBe(1);
});

// ── Orang tua/wali (usulan B) ─────────────────────────────────────────────────

test('kakak-adik: orang tua dengan nama & HP sama dipakai ulang; HP berbeda menjadi orang baru', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->smp->id, [
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();
    $persons = sisCount('persons');

    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->mda->id, [
        'name' => 'Aisyah Fauzi',
        'gender' => 'P',
        'guardians' => [
            ['relation' => 'IBU', 'name' => ' siti  AMINAH ', 'phone' => '0813-1111-2222'],
            ['relation' => 'AYAH', 'name' => 'Budi Santoso', 'phone' => '081399998888'],
        ],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    $ibu = sisMembershipOf($this->tenant, 'Siti Aminah');

    // Orang baru: Aisyah + ayah. Ibu dipakai ulang.
    expect(sisCount('persons'))->toBe($persons + 2)
        ->and(StudentGuardian::query()->withoutGlobalScope('tenant')->where('guardian_membership_id', $ibu->id)->count())->toBe(2);

    // Nama sama tetapi HP berbeda: orang baru.
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->mda->id, [
        'name' => 'Umar Fauzi',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '085700000000']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    expect(Person::query()->where('name', 'Siti Aminah')->count())->toBe(2);
});

test('kontak utama lama tidak diganti saat siswa lama didaftarkan ke lembaga lain', function () {
    $this->actingAs($this->admin)->post(route('academic.students.store'), sisIsian($this->mda->id, [
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222']],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();
    $ahmad = sisMembershipOf($this->tenant, 'Ahmad Fauzi');

    $this->actingAs($this->operator)->post(route('academic.students.store'), sisIsian($this->smp->id, [
        'existing_membership_id' => $ahmad->id,
        'guardians' => [
            // Ibu yang sama (sudah tertaut) tidak dibuat ulang; ayah ditambahkan tanpa menjadi kontak utama.
            ['relation' => 'AYAH', 'name' => 'Budi Santoso', 'phone' => '081399998888'],
            ['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222'],
        ],
        'primary_guardian' => 0,
    ]))->assertSessionHasNoErrors();

    $links = StudentGuardian::query()->withoutGlobalScope('tenant')->where('student_id', sisStudentOf($this->tenant, 'Ahmad Fauzi')->id)->get();

    expect($links)->toHaveCount(2)
        ->and($links->where('is_primary_contact', true))->toHaveCount(1)
        ->and($links->firstWhere('is_primary_contact', true)?->guardian_membership_id)->toBe(sisMembershipOf($this->tenant, 'Siti Aminah')->id);
});
