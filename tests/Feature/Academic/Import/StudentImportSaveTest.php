<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Import\PendingStudentImport;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Impor siswa: simpan semua atau tidak sama sekali (AC-1e-b, PRD-002 AC-1e, pola HR-1e-b).
 *
 * Data uji: Ahmad Fauzi (MDA Unit 3, NISN 0012345678), Budi Santoso (SMP Unit 3, nomor induk SMP-001),
 * kelas VII-A di SMP Unit 3 tahun berjalan.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');

    foreach ([['Ahmad Fauzi', 'U3-MDA', ['nisn' => '0012345678']], ['Budi Santoso', 'U3-SMP', ['student_number' => 'SMP-001']]] as [$name, $code, $extra]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), array_merge([
            'name' => $name, 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, $code)->id,
            'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ], $extra))->assertSessionHasNoErrors();
    }

    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'grade' => 7, 'name' => 'VII-A',
    ])->assertSessionHasNoErrors();
});

const ISM_HEADER = ['nama', 'jenis_kelamin', 'tanggal_masuk', 'nisn', 'nomor_induk', 'kelas', 'nama_ayah', 'hp_ayah', 'nama_ibu', 'hp_ibu', 'kontak_utama'];

/**
 * Satu baris CSV sesuai ISM_HEADER.
 *
 * @param  array<string, string>  $values
 * @return list<string>
 */
function ismBaris(string $name, array $values = []): array
{
    $row = array_merge(array_fill_keys(ISM_HEADER, ''), ['nama' => $name, 'jenis_kelamin' => 'P', 'tanggal_masuk' => '2025-07-14'], $values);

    return array_values(array_map(fn (string $key): string => $row[$key], ISM_HEADER));
}

/**
 * @param  list<list<string>>  $rows
 */
function ismUnggah(TestCase $test, mixed $user, string $organizationId, array $rows): void
{
    $lines = [implode(';', ISM_HEADER)];

    foreach ($rows as $row) {
        $lines[] = implode(';', $row);
    }

    $test->actingAs($user)->post(route('academic.students.import.upload'), [
        'organization_id' => $organizationId,
        'file' => UploadedFile::fake()->createWithContent('siswa.csv', implode("\r\n", $lines)."\r\n"),
    ])->assertSessionHasNoErrors();
}

function ismStudent(Tenant $tenant, string $name): ?Student
{
    $membership = Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->first();

    return $membership === null ? null : Student::query()->withoutGlobalScope('tenant')->where('membership_id', $membership->id)->first();
}

// ── Simpan ────────────────────────────────────────────────────────────────────

test('simpan: siswa baru (orang tua & kelas) dan siswa lama tersimpan sekaligus; file dihapus; tercatat di audit', function () {
    ismUnggah($this, $this->operator, $this->smp->id, [
        ismBaris('Citra Lestari', ['nomor_induk' => 'smp-002', 'kelas' => 'VII-A', 'nama_ayah' => 'Hasan', 'hp_ayah' => '081211112222', 'nama_ibu' => 'Siti Aminah', 'hp_ibu' => '081311112222', 'kontak_utama' => 'IBU']),
        ismBaris('Ahmad Fauzi', ['jenis_kelamin' => 'L', 'nisn' => '0012345678']),
        ismBaris('Dewi Sartika'),
    ]);

    $persons = Person::query()->count();

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.save'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('academic.students.index', ['lembaga' => $this->smp->id]));

    $citra = ismStudent($this->tenant, 'Citra Lestari');
    $ahmad = ismStudent($this->tenant, 'Ahmad Fauzi');
    $citraEnrollment = Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $citra?->id)->sole();
    $primary = DB::table('ac_student_guardians as g')
        ->join('memberships as m', 'm.id', '=', 'g.guardian_membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('g.student_id', $citra?->id)
        ->where('g.is_primary_contact', true)
        ->value('p.name');

    // Citra, Dewi, Hasan, Siti = 4 orang baru; Ahmad orang yang sama.
    expect(Person::query()->count())->toBe($persons + 4)
        ->and($citraEnrollment->student_number)->toBe('SMP-002')
        ->and($primary)->toBe('Siti Aminah')
        ->and(DB::table('ac_class_members')->where('enrollment_id', $citraEnrollment->id)->whereNull('end_date')->exists())->toBeTrue()
        ->and(Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $ahmad?->id)->whereNull('end_date')->count())->toBe(2)
        ->and(ismStudent($this->tenant, 'Dewi Sartika'))->not->toBeNull()
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENTS_IMPORTED)->sole()->changes, true))
        ->toEqual(['lembaga' => 'U3-SMP', 'jumlah' => 3, 'siswa_lama' => 1])
        ->and(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toBe([]);
});

test('tidak bisa simpan bila ada baris Salah; tidak ada yang tersimpan dan file tetap ada', function () {
    ismUnggah($this, $this->operator, $this->smp->id, [ismBaris('Citra Lestari'), ismBaris('Dewi', ['jenis_kelamin' => 'X'])]);

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.save'))
        ->assertSessionHasErrors(['save' => 'Ada 1 baris yang salah. Perbaiki file lalu unggah ulang; tidak ada data yang disimpan.']);

    expect(ismStudent($this->tenant, 'Citra Lestari'))->toBeNull()
        ->and(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toHaveCount(1);
});

test('baris bertanda peringatan hanya tersimpan setelah dikonfirmasi, sebagai orang baru', function () {
    ismUnggah($this, $this->operator, $this->smp->id, [ismBaris('budi santoso', ['jenis_kelamin' => 'L'])]);

    $this->actingAs($this->operator)->post(route('academic.students.import.save'))
        ->assertSessionHasErrors(['save' => 'Centang konfirmasi bahwa baris bertanda peringatan adalah orang yang berbeda.']);

    $this->actingAs($this->operator)->post(route('academic.students.import.save'), ['confirm_warnings' => '1'])
        ->assertSessionHasNoErrors();

    expect(Person::query()->whereRaw('lower(name) = ?', ['budi santoso'])->count())->toBe(2);
});

test('file diperiksa ulang saat simpan: nomor induk yang baru saja dipakai siswa lain membatalkan semuanya', function () {
    ismUnggah($this, $this->operator, $this->smp->id, [ismBaris('Citra Lestari'), ismBaris('Dewi Sartika', ['nomor_induk' => 'SMP-050'])]);

    // Sesudah pratinjau, operator lain memakai nomor induk itu.
    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Eka Putri', 'gender' => 'P', 'organization_id' => $this->smp->id, 'student_number' => 'SMP-050',
        'start_date' => '2025-07-14', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->post(route('academic.students.import.save'))->assertSessionHasErrors(['save']);

    expect(ismStudent($this->tenant, 'Citra Lestari'))->toBeNull();
});

test('satu baris gagal saat disimpan: seluruh impor dibatalkan dengan nomor baris dan alasannya', function () {
    // Ayah & ibu ditulis dengan nama dan HP yang sama → orang yang sama tercatat dua kali.
    ismUnggah($this, $this->operator, $this->smp->id, [
        ismBaris('Citra Lestari'),
        ismBaris('Dewi Sartika', ['nama_ayah' => 'Hasan', 'hp_ayah' => '081211112222', 'nama_ibu' => 'Hasan', 'hp_ibu' => '081211112222']),
    ]);

    $this->actingAs($this->operator)->post(route('academic.students.import.save'))
        ->assertSessionHasErrors(['save' => 'Baris 3: Orang tua/wali ini tercatat dua kali. Tidak ada data yang disimpan.']);

    expect(ismStudent($this->tenant, 'Citra Lestari'))->toBeNull()
        ->and(Person::query()->where('name', 'Hasan')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::STUDENTS_IMPORTED)->exists())->toBeFalse();
});

test('simpan tanpa file yang menunggu ditolak; tanpa izin kelola 403', function () {
    $this->actingAs($this->operator)->post(route('academic.students.import.save'))
        ->assertSessionHasErrors(['save' => 'Belum ada file impor. Unggah file terlebih dahulu.']);

    $this->actingAs(DevAccounts::user('kepala.unit1'))->post(route('academic.students.import.save'))->assertForbidden();
});
