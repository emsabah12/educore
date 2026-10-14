<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Import\EmployeeImportColumns;
use Modules\HR\Application\Import\EmployeeImportPreview;
use Modules\HR\Application\Import\EmployeeImportReader;
use Modules\HR\Application\Import\EmployeeImportRow;
use Modules\HR\Application\Import\EmployeeImportValidator;
use Modules\HR\Application\Import\ImportFileException;
use Modules\HR\Application\Import\PendingImportStore;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Impor pegawai: template, unggah, dan pratinjau (HR-1e-a, PRD-001 §6.2). Belum ada yang disimpan.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

const IMPOR_KOLOM_WAJIB = ['nama', 'jenis_kelamin', 'jenis_pegawai', 'tanggal_mulai', 'kode_lembaga', 'jabatan'];

/**
 * Isian satu baris yang benar, bisa ditimpa per kolom.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function imporBaris(array $override = []): array
{
    return array_merge([
        'nama' => 'Ani Rahmawati',
        'jenis_kelamin' => 'P',
        'jenis_pegawai' => 'TETAP',
        'tanggal_mulai' => '2020-07-01',
        'kode_lembaga' => 'U1-MTS',
        'jabatan' => 'GURU',
    ], $override);
}

/**
 * @param  list<array<string, mixed>>  $rows  baris data (kolom => nilai)
 */
function imporPeriksa(Tenant $tenant, array $rows): EmployeeImportPreview
{
    $lines = [];

    foreach ($rows as $index => $values) {
        $lines[] = ['line' => $index + 2, 'values' => $values];
    }

    return app(EmployeeImportValidator::class)->validate($tenant->id, $lines);
}

/**
 * @param  list<string>  $header
 * @param  list<list<string>>  $rows
 */
function imporCsv(array $header, array $rows, string $delimiter = ',', string $name = 'pegawai.csv'): UploadedFile
{
    $lines = [implode($delimiter, $header)];

    foreach ($rows as $row) {
        $lines[] = implode($delimiter, array_map(fn (string $value): string => str_contains($value, $delimiter) ? '"'.$value.'"' : $value, $row));
    }

    return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n");
}

/**
 * @param  list<string>  $header
 * @param  list<list<mixed>>  $rows
 */
function imporXlsx(array $header, array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'impor-uji-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Pegawai');
    $writer->addRow(Row::fromValues($header));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'pegawai.xlsx', null, null, true);
}

function imporKatalog(Tenant $tenant, CatalogKind $kind, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

function imporPegawaiLama(Tenant $tenant, string $name, ?string $birthDate = null, ?string $number = null, ?string $nuptk = null): Employee
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Female,
        birthPlace: null,
        birthDate: $birthDate,
        phone: null,
        address: null,
        employeeNumber: $number,
        nuptk: $nuptk,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: imporKatalog($tenant, CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2019-01-01',
        organizationId: CoreFixtures::node($tenant, 'U1-MA')->id,
        positionId: imporKatalog($tenant, CatalogKind::Position, 'GURU'),
    ));
}

/**
 * @return list<string> kolom yang punya kesalahan
 */
function imporKolomSalah(EmployeeImportRow $row): array
{
    return array_values(array_unique(array_map(fn (array $error): string => $error['column'], $row->errors)));
}

// ── Template ──────────────────────────────────────────────────────────────────

test('template berisi lembar Pegawai, Petunjuk, dan Kode milik yayasan ini', function () {
    $response = $this->actingAs($this->admin)->get(route('hr.employees.import.template'));

    $response->assertOk()->assertDownload('template-impor-pegawai.xlsx');

    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);

    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);

            if (count($rows) >= 40) {
                break;
            }
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Pegawai', 'Petunjuk', 'Kode'])
        ->and(array_map(fn (string $header): string => EmployeeImportColumns::normalizeHeader($header), $sheets['Pegawai'][0]))->toBe(EmployeeImportColumns::keys())
        ->and($sheets['Pegawai'][0][0])->toBe('nama *');

    $codes = array_merge(...array_map(fn (array $row): array => array_values(array_filter($row, 'is_string')), $sheets['Kode']));

    expect($codes)->toContain('TETAP', 'GTY', 'GURU', 'U1-MTS', 'D4_S1', 'L');

    // Template kosong dikenali judul kolomnya, tetapi belum berisi data pegawai.
    expect(fn () => app(EmployeeImportReader::class)->read($path, 'xlsx'))->toThrow(ImportFileException::class, 'Tidak ada baris data pegawai');

    @unlink($path);
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('selain Admin SDM / Admin Yayasan di tingkat Yayasan ditolak 403', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)->get(route('hr.employees.import'))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.employees.import.template'))->assertForbidden();
    $this->actingAs($kepala)
        ->post(route('hr.employees.import.upload'), ['file' => imporCsv(IMPOR_KOLOM_WAJIB, [array_values(imporBaris())])])
        ->assertForbidden();

    expect(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toBe([]);
});

// ── Unggah & pratinjau ────────────────────────────────────────────────────────

test('CSV dengan pemisah titik koma dibaca dan dipratinjau tanpa menyimpan pegawai', function () {
    $before = Employee::query()->withoutGlobalScope('tenant')->count();
    $file = imporCsv(
        [...IMPOR_KOLOM_WAJIB, 'nuptk', 'no_hp'],
        [
            [...array_values(imporBaris()), '0012345678901234', '0812-3456-7890'],
            [...array_values(imporBaris(['nama' => 'Dewi; S.Pd', 'jabatan' => 'ustadz', 'kode_lembaga' => 'u1-mda'])), '', ''],
        ],
        delimiter: ';',
    );

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.upload'), ['file' => $file])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.employees.import'));

    $this->actingAs($this->admin)
        ->get(route('hr.employees.import'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/import')
            ->where('pending.name', 'pegawai.csv')
            ->where('preview.total', 2)
            ->where('preview.ready', 2)
            ->where('preview.error', 0)
            ->where('preview.canSave', true)
            ->where('preview.rows.1.name', 'Dewi; S.Pd')
            ->where('preview.rows.1.line', 3)
            ->where('preview.rows.1.position', 'Ustadz/Ustadzah'));

    expect(Employee::query()->withoutGlobalScope('tenant')->count())->toBe($before)
        ->and(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toHaveCount(1);
});

test('XLSX: sel tanggal, nomor seri tanggal, dan teks tanggal dikenali', function () {
    $file = imporXlsx(
        [...IMPOR_KOLOM_WAJIB, 'tanggal_lahir'],
        [
            ['Ani', 'P', 'TETAP', new DateTimeImmutable('2020-07-01'), 'U1-MTS', 'GURU', 32994],
            ['Budi', 'L', 'kontrak', '15/07/2021', 'U1-MA', 'GURU', '1985-02-01'],
        ],
    );

    $rows = app(EmployeeImportReader::class)->read($file->getPathname(), 'xlsx');
    $preview = app(EmployeeImportValidator::class)->validate($this->tenant->id, $rows);

    expect($preview->canSave())->toBeTrue()
        ->and($preview->rows[0]->data?->startDate)->toBe('2020-07-01')
        ->and($preview->rows[0]->data?->birthDate)->toBe('1990-05-01')
        ->and($preview->rows[1]->data?->startDate)->toBe('2021-07-15')
        ->and($preview->rows[1]->data?->birthDate)->toBe('1985-02-01');
});

test('NUPTK dan nomor HP yang tersimpan sebagai angka di Excel ditolak', function () {
    $file = imporXlsx(
        [...IMPOR_KOLOM_WAJIB, 'nuptk', 'no_hp', 'nomor_induk'],
        [['Ani', 'P', 'TETAP', '2020-07-01', 'U1-MTS', 'GURU', 3201234567890123, 81234567890, 1024]],
    );

    $rows = app(EmployeeImportReader::class)->read($file->getPathname(), 'xlsx');
    $row = app(EmployeeImportValidator::class)->validate($this->tenant->id, $rows)->rows[0];

    // Nomor induk berupa angka bulat aman, tetap diterima sebagai teks.
    expect(imporKolomSalah($row))->toBe(['no_hp', 'nuptk'])
        ->and($row->status())->toBe(EmployeeImportRow::ERROR);
});

test('setiap kolom diperiksa dengan aturan yang sama seperti form tambah pegawai', function (array $override, string $column) {
    $row = imporPeriksa($this->tenant, [imporBaris($override)])->rows[0];

    expect(imporKolomSalah($row))->toBe([$column])
        ->and($row->data)->toBeNull();
})->with([
    'nama kosong' => [['nama' => ' '], 'nama'],
    'nama terlalu panjang' => [['nama' => str_repeat('a', 201)], 'nama'],
    'jenis kelamin salah' => [['jenis_kelamin' => 'X'], 'jenis_kelamin'],
    'tanggal lahir masa depan' => [['tanggal_lahir' => '2999-01-01'], 'tanggal_lahir'],
    'tanggal lahir tidak ada' => [['tanggal_lahir' => '31/02/1990'], 'tanggal_lahir'],
    'nomor HP salah' => [['no_hp' => '12ab'], 'no_hp'],
    'nomor induk salah' => [['nomor_induk' => 'YYS 001'], 'nomor_induk'],
    'NUPTK bukan 16 angka' => [['nuptk' => '12345'], 'nuptk'],
    'pendidikan tidak dikenal' => [['pendidikan' => 'S4'], 'pendidikan'],
    'jurusan terlalu panjang' => [['jurusan' => str_repeat('a', 151)], 'jurusan'],
    'jenis pegawai kosong' => [['jenis_pegawai' => ''], 'jenis_pegawai'],
    'jenis pegawai tidak ada' => [['jenis_pegawai' => 'MAGANG'], 'jenis_pegawai'],
    'status kepegawaian tidak ada' => [['status_kepegawaian' => 'XYZ'], 'status_kepegawaian'],
    'tanggal mulai kosong' => [['tanggal_mulai' => ''], 'tanggal_mulai'],
    'tanggal mulai masa depan' => [['tanggal_mulai' => '2999-01-01'], 'tanggal_mulai'],
    'tanggal mulai format salah' => [['tanggal_mulai' => '1 Juli 2020'], 'tanggal_mulai'],
    'lembaga tidak ada' => [['kode_lembaga' => 'U9-SMA'], 'kode_lembaga'],
    'jabatan tidak ada' => [['jabatan' => 'DIREKTUR'], 'jabatan'],
]);

test('isian opsional yang benar ikut siap disimpan, kode tidak peka huruf besar/kecil', function () {
    $row = imporPeriksa($this->tenant, [imporBaris([
        'jenis_kelamin' => 'p',
        'tempat_lahir' => ' Bandung ',
        'tanggal_lahir' => '5/3/1990',
        'no_hp' => '+62 812-3456-7890',
        'alamat' => 'Jl. Merdeka 1',
        'nomor_induk' => 'yys/101',
        'nuptk' => '1234 5678 9012 3456',
        'pendidikan' => 'd4_s1',
        'jurusan' => 'Pendidikan Matematika',
        'status_kepegawaian' => 'gty',
    ])])->rows[0];

    expect($row->status())->toBe(EmployeeImportRow::READY)
        ->and($row->data?->gender)->toBe(Gender::Female)
        ->and($row->data?->birthPlace)->toBe('Bandung')
        ->and($row->data?->birthDate)->toBe('1990-03-05')
        ->and($row->data?->phone)->toBe('+6281234567890')
        ->and($row->data?->employeeNumber)->toBe('YYS/101')
        ->and($row->data?->nuptk)->toBe('1234567890123456')
        ->and($row->data?->educationLevel)->toBe(EducationLevel::D4S1)
        ->and($row->data?->classificationId)->toBe(imporKatalog($this->tenant, CatalogKind::Classification, 'GTY'))
        ->and($row->data?->confirmDuplicate)->toBeFalse();
});

test('kode katalog dan lembaga yang nonaktif ditolak', function () {
    DB::table('hr_positions')->where('tenant_id', $this->tenant->id)->where('code', 'USTADZ')->update(['is_active' => false]);
    DB::table('organizations')->where('tenant_id', $this->tenant->id)->where('code', 'U1-MDA')->update(['status' => 'INACTIVE']);

    $row = imporPeriksa($this->tenant, [imporBaris(['jabatan' => 'USTADZ', 'kode_lembaga' => 'U1-MDA'])])->rows[0];

    expect(imporKolomSalah($row))->toBe(['kode_lembaga', 'jabatan']);
});

test('nomor induk dan NUPTK tidak boleh dipakai pegawai lain atau dobel di dalam file', function () {
    imporPegawaiLama($this->tenant, 'Pegawai Lama', number: 'YYS/001', nuptk: '1111222233334444');

    $preview = imporPeriksa($this->tenant, [
        imporBaris(['nama' => 'A', 'nomor_induk' => 'yys/001']),
        imporBaris(['nama' => 'B', 'nuptk' => '1111222233334444']),
        imporBaris(['nama' => 'C', 'nomor_induk' => 'YYS/200', 'nuptk' => '9999888877776666']),
        imporBaris(['nama' => 'D', 'nomor_induk' => 'YYS/200', 'nuptk' => '9999888877776666']),
    ]);

    expect(imporKolomSalah($preview->rows[0]))->toBe(['nomor_induk'])
        ->and(imporKolomSalah($preview->rows[1]))->toBe(['nuptk'])
        ->and($preview->rows[2]->status())->toBe(EmployeeImportRow::READY)
        ->and(imporKolomSalah($preview->rows[3]))->toBe(['nomor_induk', 'nuptk'])
        ->and($preview->rows[3]->errors[0]['message'])->toBe('Sama dengan baris 4.')
        ->and($preview->canSave())->toBeFalse();
});

test('nama + tanggal lahir yang sama dengan anggota yayasan atau baris lain hanya menjadi peringatan', function () {
    imporPegawaiLama($this->tenant, 'Siti Aminah', birthDate: '1988-04-12');

    $preview = imporPeriksa($this->tenant, [
        imporBaris(['nama' => 'SITI AMINAH', 'tanggal_lahir' => '12/04/1988']),
        imporBaris(['nama' => 'Rudi', 'tanggal_lahir' => '1990-01-01']),
        imporBaris(['nama' => 'rudi', 'tanggal_lahir' => '1990-01-01']),
        imporBaris(['nama' => 'Rudi', 'tanggal_lahir' => '1991-01-01']),
    ]);

    expect($preview->rows[0]->status())->toBe(EmployeeImportRow::WARNING)
        ->and($preview->rows[0]->warnings[0])->toContain('Siti Aminah (pegawai)')
        ->and($preview->rows[1]->status())->toBe(EmployeeImportRow::READY)
        ->and($preview->rows[2]->warnings)->toBe(['Nama dan tanggal lahir sama dengan baris 3.'])
        ->and($preview->rows[3]->status())->toBe(EmployeeImportRow::READY)
        ->and($preview->canSave())->toBeTrue()
        ->and($preview->hasWarnings())->toBeTrue()
        ->and($preview->rows[0]->data?->confirmDuplicate)->toBeTrue();
});

test('anggota yayasan lain dengan nama dan tanggal lahir sama tidak memicu peringatan', function () {
    $orangLain = Person::query()->create(['name' => 'Siti Aminah', 'birth_date' => '1988-04-12']);
    app(GrantMembership::class)->handle($orangLain->id, CoreFixtures::tenant('YYS-B')->id);

    $row = imporPeriksa($this->tenant, [imporBaris(['nama' => 'Siti Aminah', 'tanggal_lahir' => '1988-04-12'])])->rows[0];

    expect($row->status())->toBe(EmployeeImportRow::READY);
});

/**
 * @return array{0: UploadedFile, 1: string} file uji dan pesan yang diharapkan
 */
function imporFileBuruk(string $case): array
{
    return match ($case) {
        'bukan xlsx/csv' => [UploadedFile::fake()->createWithContent('pegawai.txt', "nama\nAni\n"), 'File harus berformat .xlsx atau .csv.'],
        'xlsx rusak' => [UploadedFile::fake()->createWithContent('pegawai.xlsx', 'bukan zip'), 'File tidak bisa dibaca. Pastikan file berformat .xlsx atau .csv dan tidak rusak.'],
        'judul kolom kurang' => [imporCsv(['nama', 'jenis_kelamin'], [['Ani', 'P']]), 'Judul kolom wajib tidak ditemukan: jenis_pegawai, tanggal_mulai, kode_lembaga, jabatan. Pakai template impor.'],
        'tanpa baris data' => [imporCsv(IMPOR_KOLOM_WAJIB, []), 'Tidak ada baris data pegawai di bawah judul kolom.'],
        'lebih dari 1000 baris' => [imporCsv(IMPOR_KOLOM_WAJIB, array_fill(0, 1001, array_values(imporBaris()))), 'File berisi lebih dari 1000 baris pegawai. Bagi menjadi beberapa file.'],
        'judul kolom dobel' => [imporCsv([...IMPOR_KOLOM_WAJIB, 'Nama'], [[...array_values(imporBaris()), 'X']]), 'Judul kolom "Nama" muncul lebih dari sekali.'],
    };
}

test('file yang tidak bisa dipakai ditolak saat unggah dan tidak disimpan', function (string $case) {
    [$file, $message] = imporFileBuruk($case);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.upload'), ['file' => $file])
        ->assertSessionHasErrors(['file' => $message]);

    expect(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toBe([]);
})->with(['bukan xlsx/csv', 'xlsx rusak', 'judul kolom kurang', 'tanpa baris data', 'lebih dari 1000 baris', 'judul kolom dobel']);

test('baris kosong dilewati dan nomor baris mengikuti file', function () {
    $file = UploadedFile::fake()->createWithContent('pegawai.csv', implode("\n", [
        implode(',', IMPOR_KOLOM_WAJIB),
        ',,,,,',
        implode(',', array_values(imporBaris())),
        '',
    ]));

    $rows = app(EmployeeImportReader::class)->read($file->getPathname(), 'csv');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['line'])->toBe(3);
});

test('unggah ulang mengganti file lama, dan batal menghapusnya', function () {
    $file = fn () => imporCsv(IMPOR_KOLOM_WAJIB, [array_values(imporBaris())]);

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => $file()]);
    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => $file()]);

    expect(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.cancel'))
        ->assertRedirect(route('hr.employees.import'));

    expect(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toBe([]);

    $this->actingAs($this->admin)
        ->get(route('hr.employees.import'))
        ->assertInertia(fn (Assert $page) => $page->where('pending', null)->where('preview', null));
});

test('file impor milik yayasan lain di session tidak dipakai', function () {
    $id = (string) Str::uuid7();
    Storage::disk('local')->put(PendingImportStore::DIRECTORY."/{$id}.csv", implode(',', IMPOR_KOLOM_WAJIB)."\n".implode(',', array_values(imporBaris()))."\n");

    $this->actingAs($this->admin)
        ->withSession(['hr.import.pending' => ['id' => $id, 'extension' => 'csv', 'tenant_id' => (string) Str::uuid7(), 'name' => 'x.csv']])
        ->get(route('hr.employees.import'))
        ->assertInertia(fn (Assert $page) => $page->where('pending', null)->where('preview', null));

    expect(Storage::disk('local')->exists(PendingImportStore::DIRECTORY."/{$id}.csv"))->toBeFalse();
});

test('file impor yang tertinggal lebih dari 24 jam dihapus perintah hr:prune-imports', function () {
    $disk = Storage::disk('local');
    $disk->put(PendingImportStore::DIRECTORY.'/lama.csv', 'x');
    $disk->put(PendingImportStore::DIRECTORY.'/baru.csv', 'x');
    touch($disk->path(PendingImportStore::DIRECTORY.'/lama.csv'), now()->subHours(25)->getTimestamp());

    $this->artisan('hr:prune-imports')->assertSuccessful();

    expect($disk->exists(PendingImportStore::DIRECTORY.'/lama.csv'))->toBeFalse()
        ->and($disk->exists(PendingImportStore::DIRECTORY.'/baru.csv'))->toBeTrue();
});
