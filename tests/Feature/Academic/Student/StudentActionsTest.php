<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Aksi di detail siswa (AC-1c-b1, PRD-002 §4.2–§4.5, §5.3, §6, OD-AC-09): ubah data diri,
 * daftarkan ke lembaga lain, ubah pendaftaran, tutup pendaftaran.
 *
 * Data uji:
 *   Ahmad — SMP Unit 3 (nomor SMP-001) + MDA Unit 3, aktif keduanya
 *   Budi  — MTs Unit 1
 * Akun: admin.yayasan, operator.smp (Operator Akademik SMP Unit 3), operator MDA Unit 3 (dibuat di sini).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->node = fn (string $code): string => CoreFixtures::node($this->tenant, $code)->id;

    $this->actingAs($this->admin)->post(route('academic.students.store'), aktIsian('Ahmad Fauzi', ($this->node)('U3-SMP'), [
        'nisn' => '0012345678',
        'student_number' => 'SMP-001',
        'phone' => '081200001111',
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), aktIsian('Ahmad Fauzi', ($this->node)('U3-MDA'), [
        'existing_membership_id' => aktMembership($this->tenant, 'Ahmad Fauzi')->id,
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.students.store'), aktIsian('Budi Santoso', ($this->node)('U1-MTS'), [
        'nisn' => '0099999999',
        'student_number' => 'MTS-007',
    ]))->assertSessionHasNoErrors();

    $this->ahmad = aktStudent($this->tenant, 'Ahmad Fauzi');
    $this->smp = aktEnrollment($this->ahmad, ($this->node)('U3-SMP'));
    $this->mda = aktEnrollment($this->ahmad, ($this->node)('U3-MDA'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aktIsian(string $name, string $organizationId, array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'gender' => 'L',
        'organization_id' => $organizationId,
        'start_date' => '2025-07-14',
        'entry_type' => 'BARU',
    ], $overrides);
}

function aktMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()
        ->where('tenant_id', $tenant->id)
        ->whereHas('person', fn ($person) => $person->where('name', $name))
        ->sole();
}

function aktStudent(Tenant $tenant, string $name): Student
{
    return Student::query()->withoutGlobalScope('tenant')->where('membership_id', aktMembership($tenant, $name)->id)->sole();
}

function aktEnrollment(Student $student, string $organizationId): Enrollment
{
    return Enrollment::query()
        ->withoutGlobalScope('tenant')
        ->where('student_id', $student->id)
        ->where('organization_id', $organizationId)
        ->latest('start_date')
        ->firstOrFail();
}

/** Operator Akademik di MDA Unit 3 (satu penugasan → lembaga kerja langsung terpilih). */
function aktOperatorMda(Tenant $tenant): User
{
    $user = User::factory()->create();
    $assignment = CoreFixtures::assign(CoreFixtures::member($user, $tenant), CoreFixtures::node($tenant, 'U3-MDA'));
    CoreFixtures::assignmentRole($assignment, AcademicAccess::ROLE_OPERATOR_AKADEMIK);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aktProfil(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ahmad Fauzi',
        'gender' => 'L',
        'birth_place' => 'Bandung',
        'birth_date' => '2012-04-12',
        'phone' => '0812-0000-1111',
        'address' => 'Jl. Melati 1',
        'nisn' => '0012345678',
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function aktAuditChanges(string $action, ?string $subjectId = null): array
{
    // Tidak diurutkan menurut waktu (pelajaran AC-1b): pilih menurut subjek, atau pastikan hanya satu baris.
    $query = DB::table('audit_logs')->where('action', $action);

    if ($subjectId !== null) {
        $query->where('subject_id', $subjectId);
    }

    $changes = $query->sole()->changes;

    return json_decode((string) $changes, true);
}

// ── Bahan form di detail ──────────────────────────────────────────────────────

test('detail mengirim bahan form hanya untuk pengelola; tombol pendaftaran hanya di lembaga yang dikelola', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manage.profile.name', 'Ahmad Fauzi')
            ->where('manage.profile.gender', 'L')
            ->where('manage.profile.nisn', '0012345678')
            // SMP Unit 3 sudah mencatat Ahmad sebagai siswa aktif: tidak ada lembaga tujuan lain.
            ->has('manage.enrollTargets', 0)
            ->has('manage.options.endReasons', 6)
            ->where('student.enrollments.current.0.organization', 'MDA Unit 3')
            ->where('student.enrollments.current.0.can_manage', false)
            ->where('student.enrollments.current.1.organization', 'SMP Unit 3')
            ->where('student.enrollments.current.1.can_manage', true)
            ->where('student.enrollments.current.1.entry_type_code', 'BARU'),
        );

    // Kepala Unit 3 tidak ada di data uji; Koordinator MDA hanya melihat (tanpa izin kelola).
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manage', null)
            ->where('student.enrollments.current.0.can_manage', false),
        );

    // Admin Yayasan: semua lembaga aktif yang belum mencatat Ahmad.
    $this->actingAs($this->admin)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manage.profile.name', 'Ahmad Fauzi')
            ->where('student.enrollments.current.0.can_manage', true)
            ->where('manage.enrollTargets', fn ($targets) => collect($targets)->pluck('value')->doesntContain(($this->node)('U3-SMP'))
                && collect($targets)->pluck('value')->contains(($this->node)('U1-MTS'))),
        );
});

// ── Ubah data diri (OD-AC-09) ─────────────────────────────────────────────────

test('operator mengubah data diri; audit hanya mencatat nama field; HP dirapikan', function () {
    $this->actingAs($this->operator)
        ->patch(route('academic.students.update', ['student' => $this->ahmad->id]), aktProfil(['name' => '  Ahmad   Fauzi Rahman ']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $person = Person::query()->whereKey(aktMembership($this->tenant, 'Ahmad Fauzi Rahman')->person_id)->sole();

    expect($person->phone)->toBe('081200001111')
        ->and($person->birth_place)->toBe('Bandung')
        ->and($person->address)->toBe('Jl. Melati 1');

    $changes = aktAuditChanges(AcademicAuditAction::STUDENT_UPDATED);
    $json = json_encode($changes);

    expect($changes['field_diubah'])->toEqualCanonicalizing(['name', 'birth_place', 'birth_date', 'address'])
        ->and($json)->not->toContain('Melati')
        ->and($json)->not->toContain('Bandung');

    // Mengosongkan kolom opsional = menghapus datanya; NISN boleh dikosongkan.
    $this->actingAs($this->operator)
        ->patch(route('academic.students.update', ['student' => $this->ahmad->id]), aktProfil(['name' => 'Ahmad Fauzi Rahman', 'address' => '', 'nisn' => '']))
        ->assertSessionHasNoErrors();

    expect($person->fresh()?->address)->toBeNull()
        ->and($this->ahmad->fresh()?->nisn)->toBeNull();
});

test('ubah data diri: NISN milik siswa lain, jenis kelamin kosong, dan isian salah ditolak di isiannya', function () {
    $url = route('academic.students.update', ['student' => $this->ahmad->id]);

    $this->actingAs($this->operator)->patch($url, aktProfil(['nisn' => '0099999999']))
        ->assertSessionHasErrors(['nisn' => 'NISN ini sudah dipakai siswa lain di yayasan ini.']);

    $this->actingAs($this->operator)->patch($url, aktProfil(['gender' => '', 'name' => '', 'nisn' => '123', 'birth_date' => '2099-01-01', 'phone' => 'abc']))
        ->assertSessionHasErrors(['gender', 'name', 'nisn', 'birth_date', 'phone']);

    expect($this->ahmad->fresh()?->nisn)->toBe('0012345678');
});

test('data diri boleh diubah operator lembaga mana pun tempat siswa aktif; setelah keluar dari MDA, operator MDA tidak boleh lagi', function () {
    $operatorMda = aktOperatorMda($this->tenant);
    $url = route('academic.students.update', ['student' => $this->ahmad->id]);

    $this->actingAs($operatorMda)->patch($url, aktProfil(['birth_place' => 'Garut']))->assertSessionHasNoErrors();
    expect(Person::query()->whereKey(aktMembership($this->tenant, 'Ahmad Fauzi')->person_id)->value('birth_place'))->toBe('Garut');

    // Operator MDA menutup pendaftaran MDA → Ahmad tetap terlihat (alumni) tetapi data dirinya bukan lagi urusan MDA.
    $this->actingAs($operatorMda)
        ->post(route('academic.enrollments.close', ['student' => $this->ahmad->id, 'enrollment' => $this->mda->id]), [
            'end_date' => '2026-06-15',
            'end_reason' => 'LULUS',
        ])->assertSessionHasNoErrors();

    $this->actingAs($operatorMda)->patch($url, aktProfil())->assertForbidden();
    $this->actingAs($operatorMda)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('manage.profile', null));

    // Operator SMP (Ahmad masih aktif di SMP) tetap boleh.
    $this->actingAs($this->operator)->patch($url, aktProfil())->assertSessionHasNoErrors();
});

test('Admin Yayasan boleh mengubah data diri siswa yang sudah tidak aktif di mana pun', function () {
    $budi = aktStudent($this->tenant, 'Budi Santoso');
    DB::table('ac_enrollments')->where('student_id', $budi->id)->update(['end_date' => '2026-06-15', 'end_reason' => 'LULUS']);

    $this->actingAs($this->admin)
        ->patch(route('academic.students.update', ['student' => $budi->id]), aktProfil(['name' => 'Budi Santoso', 'nisn' => '0099999999']))
        ->assertSessionHasNoErrors();
});

test('ubah data diri: tanpa izin kelola 403, siswa di luar cakupan atau yayasan lain 404', function () {
    $kepala = DevAccounts::user('kepala.unit1');
    $budi = aktStudent($this->tenant, 'Budi Santoso');

    // Kepala Unit 1 melihat Budi tetapi tidak mengelola siswa.
    $this->actingAs($kepala)->patch(route('academic.students.update', ['student' => $budi->id]), aktProfil(['name' => 'Budi']))->assertForbidden();
    // Ahmad di luar cakupan Kepala Unit 1 dan operator SMP tidak melihat Budi.
    $this->actingAs($kepala)->patch(route('academic.students.update', ['student' => $this->ahmad->id]), aktProfil())->assertNotFound();
    $this->actingAs($this->operator)->patch(route('academic.students.update', ['student' => $budi->id]), aktProfil(['name' => 'Budi']))->assertNotFound();
    $this->actingAs($this->admin)->patch(route('academic.students.update', ['student' => (string) Str::uuid7()]), aktProfil())->assertNotFound();

    expect(Person::query()->where('name', 'Budi')->exists())->toBeFalse();
});

// ── Daftarkan ke lembaga lain ─────────────────────────────────────────────────

test('daftarkan ke lembaga lain: orang yang sama, satu pendaftaran baru, tercatat di audit', function () {
    $budi = aktStudent($this->tenant, 'Budi Santoso');
    $persons = Person::query()->count();

    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.store', ['student' => $budi->id]), [
            'organization_id' => ($this->node)('U1-MDA'),
            'student_number' => 'mda/01',
            'start_date' => '2025-08-01',
            'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();

    $enrollment = aktEnrollment($budi, ($this->node)('U1-MDA'));

    expect(Person::query()->count())->toBe($persons)
        ->and($enrollment->student_number)->toBe('MDA/01')
        ->and($enrollment->isOpen())->toBeTrue()
        ->and(aktAuditChanges(AcademicAuditAction::ENROLLMENT_CREATED, $enrollment->id)['lembaga'])->toBe('U1-MDA');

    // Sudah aktif di lembaga itu.
    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.store', ['student' => $budi->id]), [
            'organization_id' => ($this->node)('U1-MDA'),
            'start_date' => '2025-08-01',
            'entry_type' => 'BARU',
        ])->assertSessionHasErrors(['organization_id' => 'Orang ini sudah terdaftar aktif di lembaga tersebut.']);

    // Nomor induk milik siswa lain di lembaga tujuan.
    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.store', ['student' => $this->ahmad->id]), [
            'organization_id' => ($this->node)('U1-MDA'),
            'student_number' => 'MDA/01',
            'start_date' => '2025-08-01',
            'entry_type' => 'PINDAHAN',
        ])->assertSessionHasErrors(['student_number']);
});

test('daftarkan: lembaga di luar cakupan 404, unit bukan lembaga ditolak, tanpa izin kelola 403', function () {
    $budi = aktStudent($this->tenant, 'Budi Santoso');
    $isian = ['start_date' => '2025-08-01', 'entry_type' => 'BARU'];

    // Operator SMP hanya mengelola SMP Unit 3.
    $this->actingAs($this->operator)
        ->post(route('academic.enrollments.store', ['student' => $this->ahmad->id]), $isian + ['organization_id' => ($this->node)('U3-SMK')])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.store', ['student' => $budi->id]), $isian + ['organization_id' => ($this->node)('PONPES-U1')])
        ->assertSessionHasErrors(['organization_id' => 'Siswa hanya bisa didaftarkan ke lembaga (bukan unit atau biro).']);

    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.store', ['student' => $budi->id]), array_merge($isian, ['organization_id' => ($this->node)('U1-MDA'), 'start_date' => '2099-01-01']))
        ->assertSessionHasErrors(['start_date' => 'Tanggal masuk tidak boleh di masa depan.']);

    expect(Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $budi->id)->count())->toBe(1);

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.enrollments.store', ['student' => $budi->id]), $isian + ['organization_id' => ($this->node)('U1-MDA')])
        ->assertForbidden();
});

// ── Ubah pendaftaran ──────────────────────────────────────────────────────────

test('ubah pendaftaran memperbaiki nomor induk, tanggal masuk, jenis masuk; audit mencatat field yang berubah', function () {
    $this->actingAs($this->operator)
        ->patch(route('academic.enrollments.update', ['student' => $this->ahmad->id, 'enrollment' => $this->smp->id]), [
            'student_number' => 'smp-010',
            'start_date' => '2025-07-15',
            'entry_type' => 'PINDAHAN',
        ])->assertSessionHasNoErrors();

    $fresh = $this->smp->fresh();

    expect($fresh?->student_number)->toBe('SMP-010')
        ->and($fresh?->start_date->toDateString())->toBe('2025-07-15')
        ->and($fresh?->entry_type->value)->toBe('PINDAHAN')
        ->and(aktAuditChanges(AcademicAuditAction::ENROLLMENT_UPDATED)['field_diubah'])
        ->toEqualCanonicalizing(['student_number', 'start_date', 'entry_type']);
});

test('ubah pendaftaran: nomor milik siswa lain, tanggal sesudah keluar ditolak; lembaga lain 404, tanpa izin 403', function () {
    $budi = aktStudent($this->tenant, 'Budi Santoso');
    $budiMts = aktEnrollment($budi, ($this->node)('U1-MTS'));

    // Ahmad didaftarkan juga ke MTs Unit 1 oleh admin, lalu nomornya bentrok dengan Budi.
    $this->actingAs($this->admin)->post(route('academic.enrollments.store', ['student' => $this->ahmad->id]), [
        'organization_id' => ($this->node)('U1-MTS'),
        'start_date' => '2025-08-01',
        'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $ahmadMts = aktEnrollment($this->ahmad, ($this->node)('U1-MTS'));

    $this->actingAs($this->admin)
        ->patch(route('academic.enrollments.update', ['student' => $this->ahmad->id, 'enrollment' => $ahmadMts->id]), [
            'student_number' => 'MTS-007',
            'start_date' => '2025-08-01',
            'entry_type' => 'BARU',
        ])->assertSessionHasErrors(['student_number' => 'Nomor induk MTS-007 sudah dipakai siswa lain di lembaga ini.']);

    // Pendaftaran yang sudah ditutup: tanggal masuk tidak boleh sesudah tanggal keluar.
    DB::table('ac_enrollments')->where('id', $budiMts->id)->update(['end_date' => '2026-01-10', 'end_reason' => 'PINDAH']);

    $this->actingAs($this->admin)
        ->patch(route('academic.enrollments.update', ['student' => $budi->id, 'enrollment' => $budiMts->id]), [
            'student_number' => 'MTS-007',
            'start_date' => '2026-02-01',
            'entry_type' => 'BARU',
        ])->assertSessionHasErrors(['start_date' => 'Tanggal masuk tidak boleh sesudah tanggal keluar (2026-01-10).']);

    // Operator SMP: pendaftaran MDA Ahmad bukan lembaganya → 404; pendaftaran milik siswa lain → 404.
    $this->actingAs($this->operator)
        ->patch(route('academic.enrollments.update', ['student' => $this->ahmad->id, 'enrollment' => $this->mda->id]), [
            'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertNotFound();
    $this->actingAs($this->admin)
        ->patch(route('academic.enrollments.update', ['student' => $budi->id, 'enrollment' => $this->smp->id]), [
            'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertNotFound();

    // Koordinator MDA melihat pendaftaran MDA tetapi tidak berhak mengelola.
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)
        ->patch(route('academic.enrollments.update', ['student' => $this->ahmad->id, 'enrollment' => $this->mda->id]), [
            'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertForbidden();
});

// ── Tutup pendaftaran ─────────────────────────────────────────────────────────

test('tutup pendaftaran SMP "Lulus": kelas SMP ikut tertutup, pendaftaran MDA tetap aktif', function () {
    $yearId = DB::table('ac_academic_years')->where('tenant_id', $this->tenant->id)->where('is_current', true)->value('id');
    $classId = (string) Str::uuid7();

    DB::table('ac_classes')->insert([
        'id' => $classId, 'tenant_id' => $this->tenant->id, 'organization_id' => ($this->node)('U3-SMP'),
        'academic_year_id' => $yearId, 'grade' => 9, 'name' => 'IX-A', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('ac_class_members')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => ($this->node)('U3-SMP'),
        'academic_year_id' => $yearId, 'class_id' => $classId, 'enrollment_id' => $this->smp->id,
        'start_date' => '2025-07-14', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->operator)
        ->post(route('academic.enrollments.close', ['student' => $this->ahmad->id, 'enrollment' => $this->smp->id]), [
            'end_date' => '2026-06-15',
            'end_reason' => 'LULUS',
        ])->assertSessionHasNoErrors();

    $smp = $this->smp->fresh();

    expect($smp?->end_date?->toDateString())->toBe('2026-06-15')
        ->and($smp?->end_reason?->value)->toBe('LULUS')
        ->and($this->mda->fresh()?->isOpen())->toBeTrue()
        ->and(DB::table('ac_class_members')->where('enrollment_id', $this->smp->id)->value('end_date'))->toBe('2026-06-15')
        ->and(aktAuditChanges(AcademicAuditAction::ENROLLMENT_CLOSED))->toMatchArray([
            'lembaga' => 'U3-SMP',
            'end_reason' => 'LULUS',
            'catatan_diisi' => false,
            'kelas_ditutup' => 1,
        ]);

    // Operator SMP masih melihat Ahmad sebagai alumni dan boleh mendaftarkannya ulang.
    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->ahmad->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.enrollments.history.0.can_manage', true)
            ->where('manage.enrollTargets.0.label', 'SMP Unit 3'),
        );

    $this->actingAs($this->operator)
        ->post(route('academic.enrollments.store', ['student' => $this->ahmad->id]), [
            'organization_id' => ($this->node)('U3-SMP'),
            'student_number' => 'SMP-001',
            'start_date' => '2026-07-01',
            'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();

    expect(Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $this->ahmad->id)->count())->toBe(3)
        ->and(aktEnrollment($this->ahmad, ($this->node)('U3-SMP'))->isOpen())->toBeTrue();
});

test('tutup pendaftaran: alasan Lainnya wajib keterangan; tanggal sebelum masuk, masa depan, atau sudah ditutup ditolak', function () {
    $url = route('academic.enrollments.close', ['student' => $this->ahmad->id, 'enrollment' => $this->smp->id]);

    $this->actingAs($this->operator)->post($url, ['end_date' => '2026-06-15', 'end_reason' => 'LAINNYA', 'end_note' => '  '])
        ->assertSessionHasErrors(['end_note' => 'Tulis keterangan bila alasannya "Lainnya".']);
    $this->actingAs($this->operator)->post($url, ['end_date' => '2025-07-13', 'end_reason' => 'PINDAH'])
        ->assertSessionHasErrors(['end_date' => 'Tanggal keluar tidak boleh sebelum tanggal masuk (2025-07-14).']);
    $this->actingAs($this->operator)->post($url, ['end_date' => '2099-01-01', 'end_reason' => 'PINDAH'])
        ->assertSessionHasErrors(['end_date']);
    $this->actingAs($this->operator)->post($url, ['end_date' => '2026-06-15', 'end_reason' => 'TIDAK-ADA'])
        ->assertSessionHasErrors(['end_reason']);

    expect($this->smp->fresh()?->isOpen())->toBeTrue();

    $this->actingAs($this->operator)->post($url, ['end_date' => '2026-06-15', 'end_reason' => 'LAINNYA', 'end_note' => 'Kembali ke kampung halaman'])
        ->assertSessionHasNoErrors();

    expect($this->smp->fresh()?->end_note)->toBe('Kembali ke kampung halaman')
        ->and(json_encode(aktAuditChanges(AcademicAuditAction::ENROLLMENT_CLOSED)))->not->toContain('kampung');

    $this->actingAs($this->operator)->post($url, ['end_date' => '2026-06-16', 'end_reason' => 'PINDAH'])
        ->assertSessionHasErrors(['end_date' => 'Pendaftaran ini sudah ditutup.']);
});

test('tutup pendaftaran: operator SMP tidak bisa menutup pendaftaran MDA siswa yang sama', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.enrollments.close', ['student' => $this->ahmad->id, 'enrollment' => $this->mda->id]), [
            'end_date' => '2026-06-15',
            'end_reason' => 'LULUS',
        ])->assertNotFound();

    expect($this->mda->fresh()?->isOpen())->toBeTrue();
});
