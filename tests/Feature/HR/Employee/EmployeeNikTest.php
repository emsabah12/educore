<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Import\EmployeeImportReader;
use Modules\HR\Application\Import\EmployeeImportRow;
use Modules\HR\Application\Import\EmployeeImportValidator;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\Placement;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\Support\TestNik;

/*
 * NIK sebagai penanda unik orang (OD-HR-01 revisi 2026-10-23) dan kolom impor lembaga_tambahan
 * (PRD-001 §6.2). NIK disimpan terenkripsi di Core (person_identifiers), tidak pernah masuk audit.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

function nikKatalog(Tenant $tenant, CatalogKind $kind, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nikForm(Tenant $tenant, array $overrides = []): array
{
    return array_merge([
        'mode' => 'baru',
        'name' => 'Siti Aminah',
        'nik' => '3201 0157 0590 0001',
        'gender' => 'P',
        'birth_date' => '1990-05-17',
        'employment_type_id' => nikKatalog($tenant, CatalogKind::EmploymentType, 'TETAP'),
        'start_date' => '2020-07-01',
        'organization_id' => CoreFixtures::node($tenant, 'U1-MTS')->id,
        'position_id' => nikKatalog($tenant, CatalogKind::Position, 'GURU'),
    ], $overrides);
}

function nikPegawaiLama(Tenant $tenant, string $name, ?string $nik = null): Employee
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Male,
        birthPlace: null,
        birthDate: null,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: nikKatalog($tenant, CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2019-01-01',
        organizationId: CoreFixtures::node($tenant, 'U1-MA')->id,
        positionId: nikKatalog($tenant, CatalogKind::Position, 'GURU'),
        nik: $nik,
    ));
}

function nikPersonOf(Employee $employee): string
{
    return (string) DB::table('memberships')->where('id', $employee->membership_id)->value('person_id');
}

/**
 * @param  list<string>  $header
 * @param  list<list<mixed>>  $rows
 */
function nikXlsx(array $header, array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'nik-uji-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($header));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, 'pegawai.xlsx', null, null, true);
}

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function nikBarisImpor(array $override = []): array
{
    return array_merge([
        'nama' => 'Ani Rahmawati',
        'nik' => TestNik::for('P'),
        'jenis_kelamin' => 'P',
        'jenis_pegawai' => 'TETAP',
        'tanggal_mulai' => '2020-07-01',
        'kode_lembaga' => 'U1-MTS',
        'lembaga_tambahan' => '',
        'jabatan' => 'GURU',
    ], $override);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<EmployeeImportRow>
 */
function nikPeriksa(Tenant $tenant, array $rows): array
{
    $lines = [];

    foreach ($rows as $index => $values) {
        $lines[] = ['line' => $index + 2, 'values' => $values];
    }

    return app(EmployeeImportValidator::class)->validate($tenant->id, $lines)->rows;
}

/**
 * @return list<string>
 */
function nikPesan(EmployeeImportRow $row, string $column): array
{
    return array_values(array_map(fn (array $error): string => $error['message'], array_filter($row->errors, fn (array $error): bool => $error['column'] === $column)));
}

// ── Aturan dasar (Core) ───────────────────────────────────────────────────────

test('NIK dirapikan, disamarkan, dan dicek kecocokannya dengan tanggal lahir & jenis kelamin', function () {
    expect(PersonNik::normalize(' 3201.0157-0590 0001 '))->toBe('3201015705900001')
        ->and(PersonNik::normalize('  '))->toBeNull()
        ->and(PersonNik::isValid('320101570590000'))->toBeFalse()
        ->and(PersonNik::isValid('32010157059000AB'))->toBeFalse()
        ->and(PersonNik::mask('3201015705900001'))->toBe('3201 •••• •••• 0001')
        // Perempuan: tanggal +40.
        ->and(PersonNik::mismatch('3201015705900001', '1990-05-17', Gender::Female))->toBeNull()
        ->and(PersonNik::mismatch('3201011705900001', '1990-05-17', Gender::Male))->toBeNull()
        ->and(PersonNik::mismatch('3201015705900001', '1990-05-17', Gender::Male))->toBe('NIK tidak cocok dengan jenis kelamin. Periksa kembali bila perlu.')
        ->and(PersonNik::mismatch('3201015705900001', '1991-05-17', Gender::Male))->toBe('NIK tidak cocok dengan jenis kelamin dan tanggal lahir. Periksa kembali bila perlu.')
        // Yang belum diisi tidak diperiksa.
        ->and(PersonNik::mismatch('3201015705900001', null, null))->toBeNull();
});

// ── Form tambah pegawai ───────────────────────────────────────────────────────

test('orang baru wajib ber-NIK 16 angka', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), nikForm($this->tenant, ['nik' => '']))
        ->assertSessionHasErrors(['nik' => 'NIK wajib diisi untuk pegawai baru (16 angka sesuai KTP/KK).']);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), nikForm($this->tenant, ['nik' => '3201-0157-0590']))
        ->assertSessionHasErrors(['nik' => 'NIK harus 16 angka.']);

    expect(Employee::query()->count())->toBe(0);
});

test('NIK disimpan terenkripsi, tersamar di detail, lengkap hanya di form ubah, dan tidak masuk audit', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), nikForm($this->tenant))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->sole();
    $stored = DB::table('person_identifiers')->sole();

    expect($stored->person_id)->toBe(nikPersonOf($employee))
        ->and($stored->type)->toBe('NIK')
        ->and((string) $stored->encrypted_value)->not->toContain('3201015705900001')
        ->and(Crypt::decryptString((string) $stored->encrypted_value))->toBe('3201015705900001')
        ->and($stored->value_fingerprint)->toBe(app(PersonNik::class)->fingerprint('3201015705900001'));

    $audit = (string) DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_CREATED)->value('changes');

    expect($audit)->toContain('nik')->and($audit)->not->toContain('3201015705900001');

    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', ['employee' => $employee->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.personal.nik', '3201 •••• •••• 0001')
            ->where('employee.personal.nik_warning', null)
            ->where('manage.values.nik', '3201015705900001'));
});

test('NIK yang sudah dipakai ditolak: pegawai disebut namanya, orang yayasan lain tidak', function () {
    $lama = nikPegawaiLama($this->tenant, 'Budi Santoso', '3201011705900002');

    // Anggota yayasan ini yang belum pegawai.
    $anggota = Person::query()->create(['name' => 'Wali Murid']);
    app(GrantMembership::class)->handle($anggota->id, $this->tenant->id);
    app(PersonNik::class)->assign($anggota->id, '3201011705900003');

    // Orang di yayasan lain.
    $luar = Person::query()->create(['name' => 'Orang Yayasan Lain']);
    app(GrantMembership::class)->handle($luar->id, CoreFixtures::tenant('YYS-B')->id);
    app(PersonNik::class)->assign($luar->id, '3201011705900004');

    // Pasangan [NIK, pesan] (bukan kunci array: string angka 16 digit menjadi int di PHP).
    $cases = [
        ['3201011705900002', 'NIK ini sudah tercatat atas nama Budi Santoso (pegawai).'],
        ['3201011705900003', 'NIK ini milik Wali Murid, anggota yayasan yang belum menjadi pegawai. Jadikan pegawai lewat Tambah pegawai → "Anggota yayasan".'],
        ['3201011705900004', 'NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.'],
    ];

    foreach ($cases as [$nik, $message]) {
        $this->actingAs($this->admin)
            ->post(route('hr.employees.store'), nikForm($this->tenant, ['nik' => $nik, 'gender' => 'L']))
            ->assertSessionHasErrors(['nik' => $message]);
    }

    expect(Employee::query()->count())->toBe(1)
        ->and(Person::query()->where('name', 'Siti Aminah')->exists())->toBeFalse()
        ->and(nikPersonOf($lama))->toBe(app(PersonNik::class)->ownerOf('3201011705900002'));
});

test('anggota yayasan tanpa NIK wajib diisi NIK-nya; yang sudah ber-NIK boleh dikosongkan', function () {
    $tanpaNik = Person::query()->create(['name' => 'Anggota Tanpa NIK', 'gender' => 'L']);
    $membershipA = app(GrantMembership::class)->handle($tanpaNik->id, $this->tenant->id);
    $denganNik = Person::query()->create(['name' => 'Anggota Ber-NIK', 'gender' => 'L']);
    $membershipB = app(GrantMembership::class)->handle($denganNik->id, $this->tenant->id);
    app(PersonNik::class)->assign($denganNik->id, '3201011705900009');

    $this->actingAs($this->admin)
        ->get(route('hr.employees.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->firstWhere('name', 'Anggota Ber-NIK')['has_nik'] === true
                && collect($members)->firstWhere('name', 'Anggota Tanpa NIK')['has_nik'] === false));

    $form = fn (string $membershipId, string $nik): array => nikForm($this->tenant, [
        'mode' => 'anggota', 'membership_id' => $membershipId, 'name' => '', 'gender' => '', 'nik' => $nik, 'birth_date' => '',
    ]);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), $form($membershipA->id, ''))
        ->assertSessionHasErrors(['nik' => 'NIK wajib diisi untuk pegawai baru (16 angka sesuai KTP/KK).']);

    $this->actingAs($this->admin)->post(route('hr.employees.store'), $form($membershipA->id, '3201011705900010'))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('hr.employees.store'), $form($membershipB->id, ''))->assertSessionHasNoErrors();

    expect(app(PersonNik::class)->valueOf($tanpaNik->id))->toBe('3201011705900010')
        ->and(app(PersonNik::class)->valueOf($denganNik->id))->toBe('3201011705900009')
        ->and(Employee::query()->count())->toBe(2);
});

// ── Ubah profil ───────────────────────────────────────────────────────────────

test('ubah profil: pegawai lama bisa dilengkapi NIK, NIK tidak bisa dikosongkan atau memakai milik orang lain', function () {
    $ani = nikPegawaiLama($this->tenant, 'Ani');
    nikPegawaiLama($this->tenant, 'Budi', '3201011705900002');
    $url = route('hr.employees.update', ['employee' => $ani->id]);
    $form = fn (string $nik): array => ['name' => 'Ani', 'gender' => 'L', 'nik' => $nik];

    // Tanpa kolom NIK sama sekali (form lama): tidak mengubah apa pun.
    $this->actingAs($this->admin)->patch($url, ['name' => 'Ani', 'gender' => 'L'])->assertSessionHasNoErrors();
    expect(app(PersonNik::class)->valueOf(nikPersonOf($ani)))->toBeNull();

    $this->actingAs($this->admin)->patch($url, $form('3201011705900002'))
        ->assertSessionHasErrors(['nik' => 'NIK ini sudah tercatat atas nama Budi (pegawai).']);

    $this->actingAs($this->admin)->patch($url, $form('3201 0117 0590 0005'))->assertSessionHasNoErrors();
    expect(app(PersonNik::class)->valueOf(nikPersonOf($ani)))->toBe('3201011705900005');

    $this->actingAs($this->admin)->patch($url, $form(''))
        ->assertSessionHasErrors(['nik' => 'NIK yang sudah tercatat tidak bisa dikosongkan. Perbaiki angkanya bila salah.']);

    // Salah ketik bisa diperbaiki; fingerprint lama tidak tertinggal.
    $this->actingAs($this->admin)->patch($url, $form('3201011705900006'))->assertSessionHasNoErrors();

    expect(app(PersonNik::class)->ownerOf('3201011705900005'))->toBeNull()
        ->and(DB::table('person_identifiers')->count())->toBe(2);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_UPDATED)->pluck('changes')->implode(' ');

    expect($audit)->toContain('nik')
        ->and($audit)->not->toContain('3201011705900005')
        ->and($audit)->not->toContain('3201011705900006');
});

test('detail pegawai memberi peringatan bila NIK tidak cocok dengan tanggal lahir; tanpa izin data pribadi NIK tidak dikirim', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.store'), nikForm($this->tenant, ['birth_date' => '1991-05-17']))
        ->assertSessionHasNoErrors();

    $employee = Employee::query()->sole();

    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', ['employee' => $employee->id]))
        ->assertInertia(fn (Assert $page) => $page->where('employee.personal.nik_warning', 'NIK tidak cocok dengan tanggal lahir. Periksa kembali bila perlu.'));

    $kepalaUnit2 = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $kepalaUnit2, null, Jenjang::Mda);

    // Pegawai MTs tidak terlihat oleh Koordinator MDA (404), jadi NIK pun tidak.
    $this->actingAs($kepalaUnit2)->get(route('hr.employees.show', ['employee' => $employee->id]))->assertNotFound();
});

// ── Impor ─────────────────────────────────────────────────────────────────────

test('impor: NIK wajib, bukan angka Excel, tidak dobel di file, dan belum terdaftar', function () {
    nikPegawaiLama($this->tenant, 'Pegawai Lama', '3201011705900002');

    $rows = nikPeriksa($this->tenant, [
        nikBarisImpor(['nama' => 'A', 'nik' => '']),
        nikBarisImpor(['nama' => 'B', 'nik' => '12345']),
        nikBarisImpor(['nama' => 'C', 'nik' => '3201011705900002', 'jenis_kelamin' => 'L']),
        nikBarisImpor(['nama' => 'D', 'nik' => '3201014105900007']),
        nikBarisImpor(['nama' => 'E', 'nik' => '3201 0141 0590 0007']),
    ]);

    expect(nikPesan($rows[0], 'nik'))->toBe(['Wajib diisi (16 angka sesuai KTP/KK).'])
        ->and(nikPesan($rows[1], 'nik'))->toBe(['Harus 16 angka.'])
        ->and(nikPesan($rows[2], 'nik'))->toBe(['NIK ini sudah tercatat atas nama Pegawai Lama (pegawai).'])
        ->and($rows[3]->status())->toBe(EmployeeImportRow::READY)
        ->and(nikPesan($rows[4], 'nik'))->toBe(['Sama dengan baris 5.']);

    $file = nikXlsx(['nama', 'nik', 'jenis_kelamin', 'jenis_pegawai', 'tanggal_mulai', 'kode_lembaga', 'jabatan'], [
        ['Ani', 3201014105900008, 'P', 'TETAP', '2020-07-01', 'U1-MTS', 'GURU'],
    ]);
    $read = app(EmployeeImportReader::class)->read($file->getPathname(), 'xlsx');
    $row = app(EmployeeImportValidator::class)->validate($this->tenant->id, $read)->rows[0];

    expect(nikPesan($row, 'nik'))->toBe(['Tersimpan sebagai angka sehingga digitnya bisa berubah. Ubah format sel menjadi Teks lalu ketik ulang.']);
});

test('impor: NIK yang tidak cocok dengan tanggal lahir menjadi peringatan, bukan kesalahan', function () {
    $row = nikPeriksa($this->tenant, [nikBarisImpor(['nik' => '3201014105900008', 'tanggal_lahir' => '1990-06-01'])])[0];

    expect($row->status())->toBe(EmployeeImportRow::WARNING)
        ->and($row->warnings)->toBe(['NIK tidak cocok dengan tanggal lahir. Periksa kembali bila perlu.'])
        ->and($row->data?->nik)->toBe('3201014105900008');
});

test('impor: lembaga_tambahan membuat penempatan tambahan dengan jabatan & tanggal mulai yang sama', function () {
    $rows = nikPeriksa($this->tenant, [
        nikBarisImpor(['nama' => 'Guru Banyak Lembaga', 'kode_lembaga' => 'U1-MTS', 'lembaga_tambahan' => ' u1-mda ; U1-MA ']),
    ]);

    expect($rows[0]->status())->toBe(EmployeeImportRow::READY)
        ->and($rows[0]->toArray()['extraOrganizations'])->toHaveCount(2);

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => UploadedFile::fake()->createWithContent('pegawai.csv', implode("\n", [
        'nama,nik,jenis_kelamin,jenis_pegawai,tanggal_mulai,kode_lembaga,lembaga_tambahan,jabatan',
        'Guru Banyak Lembaga,'.TestNik::for('L').',L,TETAP,2020-07-01,U1-MTS,"U1-MDA, U1-MA",GURU',
    ])."\n")])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('hr.employees.import.save'))->assertSessionHasNoErrors();

    $employee = Employee::query()->sole();
    $placements = Placement::query()->where('employee_id', $employee->id)->get();
    $codes = fn (bool $primary): array => $placements->where('is_primary', $primary)
        ->map(fn (Placement $placement): string => (string) DB::table('organizations')->where('id', $placement->organization_id)->value('code'))
        ->sort()->values()->all();

    expect($codes(true))->toBe(['U1-MTS'])
        ->and($codes(false))->toBe(['U1-MA', 'U1-MDA'])
        ->and($placements->pluck('start_date')->map(fn ($date) => $date->toDateString())->unique()->values()->all())->toBe(['2020-07-01'])
        ->and($placements->pluck('position_id')->unique()->count())->toBe(1);

    $audit = (string) DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_CREATED)->value('changes');

    expect($audit)->toContain('lembaga_tambahan')->and($audit)->toContain('U1-MDA');
});

test('impor: lembaga_tambahan yang tidak dikenal, nonaktif, dobel, atau sama dengan lembaga utama ditolak', function () {
    DB::table('organizations')->where('tenant_id', $this->tenant->id)->where('code', 'U1-MA')->update(['status' => 'INACTIVE']);

    $rows = nikPeriksa($this->tenant, [
        nikBarisImpor(['nama' => 'A', 'lembaga_tambahan' => 'U9-SMA']),
        nikBarisImpor(['nama' => 'B', 'lembaga_tambahan' => 'U1-MA']),
        nikBarisImpor(['nama' => 'C', 'lembaga_tambahan' => 'U1-MDA, u1-mda']),
        nikBarisImpor(['nama' => 'D', 'lembaga_tambahan' => 'u1-mts']),
    ]);

    expect(nikPesan($rows[0], 'lembaga_tambahan'))->toBe(['Kode lembaga "U9-SMA" tidak ada di yayasan ini. Lihat lembar Kode.'])
        ->and(nikPesan($rows[1], 'lembaga_tambahan'))->toBe(['Lembaga "U1-MA" sudah nonaktif.'])
        ->and(nikPesan($rows[2], 'lembaga_tambahan'))->toBe(['Lembaga "u1-mda" ditulis lebih dari sekali.'])
        ->and(nikPesan($rows[3], 'lembaga_tambahan'))->toBe(['Lembaga "u1-mts" sudah menjadi lembaga utama (kode_lembaga).'])
        ->and(array_map(fn (EmployeeImportRow $row): ?NewEmployeeData => $row->data, $rows))->toBe([null, null, null, null]);
});
