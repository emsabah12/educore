<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;

/*
 * Penjaga data Academic di database (PRD-002 §4.2–§4.3). Service AC-1 memeriksa aturan yang
 * sama lebih dulu; constraint ini lapisan terakhir bila service dilewati.
 *
 * Insert yang diharapkan gagal dibungkus DB::transaction agar transaksi test tetap bisa dipakai.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->calendarId = acInsert('ac_academic_calendars', [
        'tenant_id' => $this->tenant->id,
        'name' => 'Kalender Yayasan',
        'is_default' => true,
    ]);
    $this->yearId = acInsert('ac_academic_years', [
        'tenant_id' => $this->tenant->id,
        'calendar_id' => $this->calendarId,
        'name' => '2026/2027',
        'start_date' => '2026-07-01',
        'end_date' => '2027-06-30',
        'is_current' => true,
    ]);
});

/**
 * Sisipkan baris langsung ke database (melewati service). Mengembalikan ID baru.
 *
 * @param  array<string, mixed>  $values
 */
function acInsert(string $table, array $values): string
{
    $id = (string) Str::uuid7();

    DB::table($table)->insert(array_merge([
        'id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $values));

    return $id;
}

function acRejected(Closure $insert): void
{
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
}

function acMember(Tenant $tenant): Membership
{
    return CoreFixtures::member(User::factory()->create(), $tenant);
}

function acStudent(Tenant $tenant, ?string $nisn = null): string
{
    return acInsert('ac_students', [
        'tenant_id' => $tenant->id,
        'membership_id' => acMember($tenant)->id,
        'nisn' => $nisn,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function acEnrollment(Tenant $tenant, string $studentId, string $organizationCode, array $overrides = []): string
{
    return acInsert('ac_enrollments', array_merge([
        'tenant_id' => $tenant->id,
        'student_id' => $studentId,
        'organization_id' => CoreFixtures::node($tenant, $organizationCode)->id,
        'start_date' => '2026-07-13',
    ], $overrides));
}

function acClass(Tenant $tenant, string $yearId, string $organizationCode, string $name): string
{
    return acInsert('ac_classes', [
        'tenant_id' => $tenant->id,
        'organization_id' => CoreFixtures::node($tenant, $organizationCode)->id,
        'academic_year_id' => $yearId,
        'grade' => 7,
        'name' => $name,
    ]);
}

// ── Kalender ──────────────────────────────────────────────────────────────────

test('kalender: satu default per yayasan, nama unik tanpa beda huruf besar/kecil', function () {
    acInsert('ac_academic_calendars', ['tenant_id' => $this->tenant->id, 'name' => 'Kalender MDA']);

    acRejected(fn () => acInsert('ac_academic_calendars', ['tenant_id' => $this->tenant->id, 'name' => 'Lain', 'is_default' => true]));
    acRejected(fn () => acInsert('ac_academic_calendars', ['tenant_id' => $this->tenant->id, 'name' => 'kalender mda']));
    acRejected(fn () => acInsert('ac_academic_calendars', ['tenant_id' => $this->tenant->id, 'name' => '   ']));

    // Yayasan lain boleh memakai nama yang sama dan punya default sendiri.
    acInsert('ac_academic_calendars', ['tenant_id' => CoreFixtures::tenant('YYS-LAIN')->id, 'name' => 'Kalender Yayasan', 'is_default' => true]);

    expect(DB::table('ac_academic_calendars')->count())->toBe(3);
});

test('tahun ajaran: format nama, satu tahun berjalan per kalender, kalender dari yayasan yang sama', function () {
    $tahun = fn (array $overrides) => acInsert('ac_academic_years', array_merge([
        'tenant_id' => $this->tenant->id,
        'calendar_id' => $this->calendarId,
        'name' => '2027/2028',
        'start_date' => '2027-07-01',
        'end_date' => '2028-06-30',
    ], $overrides));

    acRejected(fn () => $tahun(['name' => '2027/2029']));
    acRejected(fn () => $tahun(['name' => '2027-2028']));
    acRejected(fn () => $tahun(['name' => 'abcd/efgh']));
    acRejected(fn () => $tahun(['name' => '2026/2027']));
    acRejected(fn () => $tahun(['is_current' => true]));
    acRejected(fn () => $tahun(['end_date' => '2027-07-01']));

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    acRejected(fn () => $tahun(['tenant_id' => $tenantLain->id]));

    // Tahun Hijriah juga memakai bentuk TTTT/TTTT.
    $tahun(['name' => '1447/1448']);

    expect(DB::table('ac_academic_years')->count())->toBe(2);
});

test('semester: urutan 1–4 tidak dobel dan tanggal selesai tidak sebelum mulai', function () {
    $semester = fn (array $overrides) => acInsert('ac_terms', array_merge([
        'tenant_id' => $this->tenant->id,
        'academic_year_id' => $this->yearId,
        'sequence' => 1,
        'name' => 'Semester Ganjil',
        'start_date' => '2026-07-01',
        'end_date' => '2026-12-31',
    ], $overrides));

    $semester([]);

    acRejected(fn () => $semester([]));
    acRejected(fn () => $semester(['sequence' => 5]));
    acRejected(fn () => $semester(['sequence' => 2, 'start_date' => '2027-02-01', 'end_date' => '2027-01-01']));

    expect(DB::table('ac_terms')->count())->toBe(1);
});

// ── Siswa & orang tua/wali ────────────────────────────────────────────────────

test('siswa: satu profil per orang, NISN 10 angka dan unik per yayasan', function () {
    $membership = acMember($this->tenant);
    acInsert('ac_students', ['tenant_id' => $this->tenant->id, 'membership_id' => $membership->id, 'nisn' => '0012345678']);

    acRejected(fn () => acInsert('ac_students', ['tenant_id' => $this->tenant->id, 'membership_id' => $membership->id]));
    acRejected(fn () => acStudent($this->tenant, '0012345678'));
    acRejected(fn () => acStudent($this->tenant, '123456789'));
    acRejected(fn () => acStudent($this->tenant, '00123456ab'));

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    acRejected(fn () => acInsert('ac_students', ['tenant_id' => $this->tenant->id, 'membership_id' => acMember($tenantLain)->id]));

    // NISN yang sama boleh di yayasan lain (data yayasan terpisah).
    acStudent($tenantLain, '0012345678');

    expect(DB::table('ac_students')->count())->toBe(2);
});

test('orang tua/wali: hubungan sah, tidak dobel, satu kontak utama, dari yayasan yang sama', function () {
    $studentId = acStudent($this->tenant);
    $ibu = acMember($this->tenant);
    $ayah = acMember($this->tenant);

    $relasi = fn (string $membershipId, array $overrides = []) => acInsert('ac_student_guardians', array_merge([
        'tenant_id' => $this->tenant->id,
        'student_id' => $studentId,
        'guardian_membership_id' => $membershipId,
        'relation' => 'IBU',
    ], $overrides));

    $relasi($ibu->id, ['is_primary_contact' => true]);

    acRejected(fn () => $relasi($ibu->id, ['relation' => 'WALI']));
    acRejected(fn () => $relasi($ayah->id, ['relation' => 'KAKAK']));
    acRejected(fn () => $relasi($ayah->id, ['relation' => 'AYAH', 'is_primary_contact' => true]));
    acRejected(fn () => $relasi(acMember(CoreFixtures::tenant('YYS-LAIN'))->id, ['relation' => 'WALI']));

    $relasi($ayah->id, ['relation' => 'AYAH']);

    expect(DB::table('ac_student_guardians')->count())->toBe(2);
});

// ── Pendaftaran ───────────────────────────────────────────────────────────────

test('pendaftaran: satu terbuka per lembaga, boleh di lembaga lain sekaligus (SMP + MDA)', function () {
    $studentId = acStudent($this->tenant);

    acEnrollment($this->tenant, $studentId, 'U3-SMP', ['student_number' => 'NIS-001']);
    acEnrollment($this->tenant, $studentId, 'U3-MDA');

    acRejected(fn () => acEnrollment($this->tenant, $studentId, 'U3-SMP'));

    // Riwayat lama di lembaga yang sama (sudah ditutup) boleh.
    acEnrollment($this->tenant, $studentId, 'U3-SMP', ['start_date' => '2020-07-13', 'end_date' => '2023-06-30', 'end_reason' => 'PINDAH']);

    expect(DB::table('ac_enrollments')->where('student_id', $studentId)->count())->toBe(3);
});

test('pendaftaran: nomor induk, jenis masuk, alasan keluar, dan lembaga diperiksa database', function () {
    $siswaA = acStudent($this->tenant);
    $siswaB = acStudent($this->tenant);

    acEnrollment($this->tenant, $siswaA, 'U3-SMP', ['student_number' => 'NIS-001']);

    // Nomor induk yang sedang dipakai siswa lain di lembaga yang sama.
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['student_number' => 'NIS-001']));
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['student_number' => 'nis-002']));
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['entry_type' => 'MUTASI']));
    // Ditutup tanpa alasan, alasan di luar daftar, atau tanggal keluar sebelum masuk.
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['end_date' => '2026-08-01']));
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['end_date' => '2026-08-01', 'end_reason' => 'NAIK_KELAS']));
    acRejected(fn () => acEnrollment($this->tenant, $siswaB, 'U3-SMP', ['end_date' => '2026-07-01', 'end_reason' => 'LULUS']));

    // Lembaga milik yayasan lain.
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $lembagaLain = CoreFixtures::unit($tenantLain, 'UNIT-LAIN');
    acRejected(fn () => acInsert('ac_enrollments', [
        'tenant_id' => $this->tenant->id,
        'student_id' => $siswaB,
        'organization_id' => $lembagaLain->id,
        'start_date' => '2026-07-13',
    ]));

    // Nomor induk yang sama di lembaga lain: boleh.
    acEnrollment($this->tenant, $siswaB, 'U3-MDA', ['student_number' => 'NIS-001', 'entry_type' => 'PINDAHAN']);

    expect(DB::table('ac_enrollments')->count())->toBe(2);
});

// ── Kelas & anggota kelas ─────────────────────────────────────────────────────

test('kelas: tingkat 1–12, nama unik per lembaga per tahun ajaran, wali kelas dari yayasan yang sama', function () {
    acClass($this->tenant, $this->yearId, 'U3-SMP', 'VII-A');

    acRejected(fn () => acClass($this->tenant, $this->yearId, 'U3-SMP', 'vii-a'));
    acRejected(fn () => acInsert('ac_classes', [
        'tenant_id' => $this->tenant->id,
        'organization_id' => CoreFixtures::node($this->tenant, 'U3-SMP')->id,
        'academic_year_id' => $this->yearId,
        'grade' => 13,
        'name' => 'XIII',
    ]));

    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $pegawaiLain = acInsert('hr_employees', [
        'tenant_id' => $tenantLain->id,
        'membership_id' => acMember($tenantLain)->id,
    ]);
    acRejected(fn () => acInsert('ac_classes', [
        'tenant_id' => $this->tenant->id,
        'organization_id' => CoreFixtures::node($this->tenant, 'U3-SMP')->id,
        'academic_year_id' => $this->yearId,
        'grade' => 7,
        'name' => 'VII-B',
        'homeroom_employee_id' => $pegawaiLain,
    ]));

    // Nama yang sama di lembaga lain: boleh.
    acClass($this->tenant, $this->yearId, 'U3-MDA', 'VII-A');

    expect(DB::table('ac_classes')->count())->toBe(2);
});

test('anggota kelas: satu kelas terbuka per tahun ajaran, kelas & pendaftaran di lembaga yang sama', function () {
    $studentId = acStudent($this->tenant);
    $smp = acEnrollment($this->tenant, $studentId, 'U3-SMP');
    $kelasA = acClass($this->tenant, $this->yearId, 'U3-SMP', 'VII-A');
    $kelasB = acClass($this->tenant, $this->yearId, 'U3-SMP', 'VII-B');
    $kelasMda = acClass($this->tenant, $this->yearId, 'U3-MDA', 'Ula 1');
    $smpId = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $mdaId = CoreFixtures::node($this->tenant, 'U3-MDA')->id;

    $anggota = fn (string $classId, string $organizationId, array $overrides = []) => acInsert('ac_class_members', array_merge([
        'tenant_id' => $this->tenant->id,
        'organization_id' => $organizationId,
        'academic_year_id' => $this->yearId,
        'class_id' => $classId,
        'enrollment_id' => $smp,
        'start_date' => '2026-07-13',
    ], $overrides));

    $pertama = $anggota($kelasA, $smpId);

    // Kelas kedua yang terbuka di tahun ajaran yang sama.
    acRejected(fn () => $anggota($kelasB, $smpId));
    // Pendaftaran SMP dimasukkan ke kelas MDA.
    acRejected(fn () => $anggota($kelasMda, $mdaId));
    // Tahun ajaran anggota tidak sama dengan tahun ajaran kelasnya.
    $tahunLain = acInsert('ac_academic_years', [
        'tenant_id' => $this->tenant->id,
        'calendar_id' => $this->calendarId,
        'name' => '2027/2028',
        'start_date' => '2027-07-01',
        'end_date' => '2028-06-30',
    ]);
    acRejected(fn () => $anggota($kelasB, $smpId, ['academic_year_id' => $tahunLain]));

    // Pindah kelas: tutup yang lama, buka yang baru.
    DB::table('ac_class_members')->where('id', $pertama)->update(['end_date' => '2026-08-01']);
    $anggota($kelasB, $smpId, ['start_date' => '2026-08-02']);

    expect(DB::table('ac_class_members')->where('enrollment_id', $smp)->count())->toBe(2);
});
