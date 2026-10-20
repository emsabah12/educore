<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Import\PendingStudentImport;
use Modules\Academic\Application\Import\StudentImportColumns;
use Modules\Academic\Application\Import\StudentImportPreview;
use Modules\Academic\Application\Import\StudentImportRow;
use Modules\Academic\Application\Import\StudentImportValidator;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\Import\SpreadsheetReader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Impor siswa: template, unggah, dan pratinjau (AC-1e-a, PRD-002 AC-1e). Belum ada yang disimpan.
 *
 * Data uji:
 *   Ahmad Fauzi — MDA Unit 3, NISN 0012345678 (siswa lama untuk impor ke SMP Unit 3)
 *   Budi Santoso — SMP Unit 3, NISN 0099999999, nomor induk SMP-001
 *   Kelas VII-A (aktif) dan VII-Z (nonaktif) di SMP Unit 3, tahun berjalan
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

    $student = fn (string $name, string $code, array $extra) => $this->actingAs($this->admin)->post(route('academic.students.store'), array_merge([
        'name' => $name, 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, $code)->id,
        'start_date' => '2025-07-14', 'entry_type' => 'BARU',
    ], $extra))->assertSessionHasNoErrors();

    $student('Ahmad Fauzi', 'U3-MDA', ['nisn' => '0012345678', 'birth_date' => '2012-04-12']);
    $student('Budi Santoso', 'U3-SMP', ['nisn' => '0099999999', 'student_number' => 'SMP-001']);

    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    foreach (['VII-A', 'VII-Z'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'grade' => 7, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'VII-Z')->update(['is_active' => false]);
});

/**
 * Isian satu baris yang benar, bisa ditimpa per kolom.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function sipBaris(array $override = []): array
{
    return array_merge([
        'nama' => 'Citra Lestari',
        'jenis_kelamin' => 'P',
        'tanggal_masuk' => '2025-07-14',
    ], $override);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function sipPeriksa(Tenant $tenant, Organization $organization, array $rows, bool $canManageClasses = true): StudentImportPreview
{
    $lines = [];

    foreach ($rows as $index => $values) {
        $lines[] = ['line' => $index + 2, 'values' => $values];
    }

    return app(StudentImportValidator::class)->validate($tenant->id, $organization, $canManageClasses, $lines);
}

/**
 * @param  list<string>  $header
 * @param  list<list<string>>  $rows
 */
function sipCsv(array $header, array $rows, string $delimiter = ';'): UploadedFile
{
    $lines = [implode($delimiter, $header)];

    foreach ($rows as $row) {
        $lines[] = implode($delimiter, $row);
    }

    return UploadedFile::fake()->createWithContent('siswa.csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n");
}

/**
 * @param  list<string>  $header
 * @param  list<list<mixed>>  $rows
 */
function sipXlsx(array $header, array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'impor-siswa-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Siswa');
    $writer->addRow(Row::fromValues($header));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'siswa.xlsx', null, null, true);
}

function sipMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();
}

// ── Halaman & template ────────────────────────────────────────────────────────

test('operator membuka halaman impor: pilihan lembaga hanya yang boleh ia kelola; tanpa izin kelola 403', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.import'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/students/import')
            ->where('organizations', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']])
            ->where('pending', null)
            ->has('columns', count(StudentImportColumns::all())),
        );

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('academic.students.import'))->assertForbidden();
});

test('template per lembaga: lembar Siswa berjudul kolom resmi, Petunjuk memuat kelas aktif saja; lembaga lain 404', function () {
    $response = $this->actingAs($this->operator)
        ->get(route('academic.students.import.template', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertDownload('template-impor-siswa-u3-smp.xlsx');

    $path = $response->baseResponse->getFile()->getPathname();

    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);

            if (count($sheets[$sheet->getName()]) > 60) {
                break;
            }
        }
    }

    $reader->close();
    @unlink($path);
    $guide = json_encode($sheets['Petunjuk']);

    expect(array_map(fn (string $header): string => SpreadsheetReader::normalizeHeader($header), $sheets['Siswa'][0]))->toBe(StudentImportColumns::keys())
        ->and($guide)->toContain('VII-A')
        ->and($guide)->not->toContain('VII-Z')
        ->and($guide)->toContain('SMP Unit 3');

    $this->actingAs($this->operator)
        ->get(route('academic.students.import.template', ['lembaga' => CoreFixtures::node($this->tenant, 'U1-MTS')->id]))
        ->assertNotFound();
});

// ── Unggah ────────────────────────────────────────────────────────────────────

test('unggah CSV ";" lalu pratinjau; tidak ada siswa yang tersimpan', function () {
    $students = Student::query()->withoutGlobalScope('tenant')->count();

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.upload'), [
            'organization_id' => $this->smp->id,
            'file' => sipCsv(
                ['nama *', 'Jenis Kelamin', 'tanggal_masuk', 'kelas', 'nama_ibu', 'hp_ibu'],
                [['Citra Lestari', 'P', '14/07/2025', 'vii-a', 'Siti Aminah', '0813-1111-2222'], ['Dewi', 'X', '2025-07-14', '', '', '']],
            ),
        ])->assertRedirect(route('academic.students.import'));

    $this->actingAs($this->operator)
        ->get(route('academic.students.import'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.name', 'siswa.csv')
            ->where('pending.organization.label', 'SMP Unit 3')
            ->where('preview.total', 2)
            ->where('preview.ready', 1)
            ->where('preview.error', 1)
            ->where('preview.canSave', false)
            ->where('preview.rows.0.className', 'VII-A')
            ->where('preview.rows.0.guardians', 1)
            ->where('preview.rows.1.errors.0.column', 'jenis_kelamin'),
        );

    expect(Student::query()->withoutGlobalScope('tenant')->count())->toBe($students)
        ->and(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toHaveCount(1);

    // Batal menghapus file.
    $this->actingAs($this->operator)->post(route('academic.students.import.cancel'))->assertRedirect(route('academic.students.import'));
    expect(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toBe([]);
});

test('unggah: lembaga di luar cakupan 404, unit ditolak, file tanpa kolom wajib ditolak dan tidak disimpan', function () {
    $file = sipCsv(['nama', 'jenis_kelamin', 'tanggal_masuk'], [['Citra', 'P', '2025-07-14']]);

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.upload'), ['organization_id' => CoreFixtures::node($this->tenant, 'U1-MTS')->id, 'file' => $file])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->post(route('academic.students.import.upload'), ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U3')->id, 'file' => $file])
        ->assertSessionHasErrors(['organization_id']);

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.upload'), [
            'organization_id' => $this->smp->id,
            'file' => sipCsv(['nama', 'jenis_kelamin'], [['Citra', 'P']]),
        ])->assertSessionHasErrors(['file' => 'Judul kolom wajib tidak ditemukan: tanggal_masuk. Pakai template impor.']);

    $this->actingAs($this->operator)
        ->post(route('academic.students.import.upload'), [
            'organization_id' => $this->smp->id,
            'file' => sipCsv(['nama', 'jenis_kelamin', 'tanggal_masuk'], []),
        ])->assertSessionHasErrors(['file' => 'Tidak ada baris data siswa di bawah judul kolom.']);

    expect(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toBe([]);
});

// ── Pemeriksaan per baris ─────────────────────────────────────────────────────

test('setiap kolom diperiksa dengan aturan yang sama seperti form Tambah siswa', function (array $override, string $column) {
    $preview = sipPeriksa($this->tenant, $this->smp, [sipBaris($override)]);

    expect($preview->rows[0]->status())->toBe(StudentImportRow::ERROR)
        ->and(array_column($preview->rows[0]->errors, 'column'))->toContain($column)
        ->and($preview->rows[0]->data)->toBeNull();
})->with([
    'nama kosong' => [['nama' => ' '], 'nama'],
    'jenis kelamin salah' => [['jenis_kelamin' => 'X'], 'jenis_kelamin'],
    'tanggal masuk kosong' => [['tanggal_masuk' => ''], 'tanggal_masuk'],
    'tanggal masuk masa depan' => [['tanggal_masuk' => '2099-01-01'], 'tanggal_masuk'],
    'tanggal lahir tidak ada' => [['tanggal_lahir' => '31/02/2012'], 'tanggal_lahir'],
    'NISN 9 angka' => [['nisn' => '001234567'], 'nisn'],
    'NISN tersimpan sebagai angka' => [['nisn' => 12345678], 'nisn'],
    'HP tersimpan sebagai angka' => [['no_hp' => 81234567890], 'no_hp'],
    'nomor induk berspasi' => [['nomor_induk' => 'SMP 9'], 'nomor_induk'],
    'jenis masuk salah' => [['jenis_masuk' => 'MUTASI'], 'jenis_masuk'],
    'kelas tidak ada' => [['kelas' => 'IX-C'], 'kelas'],
    'kelas nonaktif' => [['kelas' => 'VII-Z'], 'kelas'],
    'HP ayah tanpa nama' => [['hp_ayah' => '081211112222'], 'nama_ayah'],
    'kontak utama belum diisi namanya' => [['nama_ayah' => 'Hasan', 'kontak_utama' => 'IBU'], 'kontak_utama'],
    'kontak utama salah' => [['nama_ayah' => 'Hasan', 'kontak_utama' => 'PAMAN'], 'kontak_utama'],
]);

test('baris benar menghasilkan isian Tambah siswa: orang tua, kontak utama, kelas, jenis masuk', function () {
    $preview = sipPeriksa($this->tenant, $this->smp, [sipBaris([
        'tanggal_lahir' => 45000,
        'no_hp' => '0812 3456 7890',
        'nomor_induk' => 'smp-002',
        'jenis_masuk' => 'pindahan',
        'kelas' => ' VII-A ',
        'nama_ayah' => 'Hasan',
        'hp_ayah' => '081211112222',
        'nama_ibu' => 'Siti Aminah',
        'kontak_utama' => 'ibu',
    ])]);

    $row = $preview->rows[0];
    $data = $row->data;

    expect($row->status())->toBe(StudentImportRow::READY)
        ->and($row->mode)->toBe(StudentImportRow::MODE_NEW)
        ->and($data)->not->toBeNull()
        ->and($data?->birthDate)->toBe('2023-03-15')
        ->and($data?->phone)->toBe('081234567890')
        ->and($data?->studentNumber)->toBe('SMP-002')
        ->and($data?->entryType->value)->toBe('PINDAHAN')
        ->and($data?->classId)->toBe(SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'VII-A')->value('id'))
        ->and(array_map(fn ($guardian) => $guardian->relation, $data->guardians ?? []))->toBe([GuardianRelation::Father, GuardianRelation::Mother])
        ->and($data?->primaryGuardian)->toBe(1)
        ->and($data?->confirmNew)->toBeFalse();
});

test('kolom kelas diisi tetapi pengguna tidak berhak mengelola kelas: Salah', function () {
    $preview = sipPeriksa($this->tenant, $this->smp, [sipBaris(['kelas' => 'VII-A'])], canManageClasses: false);

    expect(array_column($preview->rows[0]->errors, 'column'))->toBe(['kelas']);
});

// ── Antar-baris & data yang sudah ada ─────────────────────────────────────────

test('NISN milik siswa yang sama = siswa lama; nama lain, sudah aktif di lembaga, atau dobel di file = Salah', function () {
    $preview = sipPeriksa($this->tenant, $this->smp, [
        sipBaris(['nama' => 'ahmad  fauzi', 'jenis_kelamin' => 'L', 'nisn' => '0012345678']),
        sipBaris(['nama' => 'Orang Lain', 'nisn' => '0099999999']),
        sipBaris(['nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L', 'nisn' => '0012345678']),
    ]);

    [$lama, $namaLain, $dobel] = $preview->rows;

    expect($lama->status())->toBe(StudentImportRow::READY)
        ->and($lama->mode)->toBe(StudentImportRow::MODE_EXISTING)
        ->and($lama->warnings)->toBe([])
        ->and($lama->data?->existingMembershipId)->toBe(sipMembership($this->tenant, 'Ahmad Fauzi')->id)
        ->and($namaLain->errors[0]['message'])->toBe('NISN ini sudah dipakai siswa lain dengan nama berbeda. Periksa lagi NISN atau namanya.')
        ->and($dobel->errors[0]['message'])->toBe('Sama dengan baris 2.')
        ->and($preview->toArray()['existing'])->toBe(1);

    // Budi sudah aktif di SMP Unit 3.
    $sudahAktif = sipPeriksa($this->tenant, $this->smp, [sipBaris(['nama' => 'Budi Santoso', 'jenis_kelamin' => 'L', 'nisn' => '0099999999'])]);

    expect($sudahAktif->rows[0]->errors[0]['message'])->toBe('Siswa ini sudah terdaftar aktif di lembaga ini.');
});

test('nomor induk milik siswa lain di lembaga ini atau dobel di file = Salah', function () {
    $preview = sipPeriksa($this->tenant, $this->smp, [
        sipBaris(['nomor_induk' => 'smp-001']),
        sipBaris(['nama' => 'Dewi', 'nomor_induk' => 'SMP-010']),
        sipBaris(['nama' => 'Eka', 'nomor_induk' => 'SMP-010']),
    ]);

    expect($preview->rows[0]->errors[0]['message'])->toBe('Nomor induk SMP-001 sudah dipakai siswa lain di lembaga ini.')
        ->and($preview->rows[1]->status())->toBe(StudentImportRow::READY)
        ->and($preview->rows[2]->errors[0]['message'])->toBe('Sama dengan baris 3.');
});

test('nama sama dengan anggota yayasan atau baris lain tanpa NISN cocok = Peringatan yang wajib dikonfirmasi', function () {
    $preview = sipPeriksa($this->tenant, $this->smp, [
        sipBaris(['nama' => 'AHMAD FAUZI', 'jenis_kelamin' => 'L']),
        sipBaris(['nama' => 'Citra Lestari']),
        sipBaris(['nama' => 'citra lestari']),
    ]);

    expect($preview->rows[0]->status())->toBe(StudentImportRow::WARNING)
        ->and($preview->rows[0]->warnings[0])->toContain('Ahmad Fauzi (lahir 2012-04-12)')
        ->and($preview->rows[0]->data?->confirmNew)->toBeTrue()
        ->and($preview->rows[1]->status())->toBe(StudentImportRow::READY)
        ->and($preview->rows[2]->warnings)->toBe(['Nama sama dengan baris 3.'])
        ->and($preview->canSave())->toBeTrue()
        ->and($preview->hasWarnings())->toBeTrue();
});

// ── File sementara ────────────────────────────────────────────────────────────

test('XLSX dibaca dari lembar Siswa; file yang tertinggal lebih dari 24 jam dihapus academic:prune-imports', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.students.import.upload'), [
            'organization_id' => $this->smp->id,
            'file' => sipXlsx(['nama', 'jenis_kelamin', 'tanggal_masuk', 'nisn'], [['Citra Lestari', 'P', new DateTimeImmutable('2025-07-14'), '0011112222']]),
        ])->assertRedirect();

    $this->actingAs($this->operator)
        ->get(route('academic.students.import'))
        ->assertInertia(fn (Assert $page) => $page->where('preview.ready', 1)->where('preview.rows.0.nisn', '0011112222'));

    $files = Storage::disk('local')->files(PendingStudentImport::DIRECTORY);
    expect($files)->toHaveCount(1);

    $this->travel(25)->hours();
    $this->artisan('academic:prune-imports')->assertSuccessful();

    expect(Storage::disk('local')->files(PendingStudentImport::DIRECTORY))->toBe([]);
});
