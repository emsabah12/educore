<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Import\PendingImportStore;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\Placement;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Impor pegawai: simpan semua atau tidak sama sekali (HR-1e-b, PRD-001 §6.2, OD-HR-05).
 */

beforeEach(function () {
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

/**
 * @param  array<string, string>  $override
 * @return array<string, string>
 */
function simpanImporBaris(string $name, array $override = []): array
{
    return array_merge([
        'nama' => $name,
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '',
        'nuptk' => '',
        'jenis_pegawai' => 'TETAP',
        'status_kepegawaian' => '',
        'tanggal_mulai' => '2020-07-01',
        'kode_lembaga' => 'U1-MTS',
        'jabatan' => 'GURU',
    ], $override);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function simpanImporCsv(array $rows): UploadedFile
{
    $lines = [implode(',', array_keys($rows[0]))];

    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return UploadedFile::fake()->createWithContent('pegawai.csv', implode("\n", $lines)."\n");
}

function simpanImporJumlahPegawai(): int
{
    return Employee::query()->withoutGlobalScope('tenant')->count();
}

function simpanImporPegawaiLama(Tenant $tenant, string $name, ?string $birthDate = null, ?string $nuptk = null): Employee
{
    $katalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()
        ->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $tenant->id,
        membershipId: null,
        name: $name,
        gender: Gender::Female,
        birthPlace: null,
        birthDate: $birthDate,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: $nuptk,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: $katalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2019-01-01',
        organizationId: CoreFixtures::node($tenant, 'U1-MA')->id,
        positionId: $katalog(CatalogKind::Position, 'GURU'),
    ));
}

test('simpan membuat semua pegawai sekaligus beserta periode dan penempatan utama, lalu file dihapus', function () {
    $employeesBefore = simpanImporJumlahPegawai();
    $usersBefore = User::query()->count();

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => simpanImporCsv([
        simpanImporBaris('Ahmad Fauzi', ['nuptk' => '1234567890123456', 'status_kepegawaian' => 'GTY']),
        simpanImporBaris('Budi Santoso', ['jenis_pegawai' => 'KONTRAK', 'kode_lembaga' => 'U1-MDA', 'jabatan' => 'USTADZ']),
        simpanImporBaris('Citra Lestari', ['tanggal_lahir' => '1990-05-01']),
    ])]);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.employees.index'));

    expect(simpanImporJumlahPegawai())->toBe($employeesBefore + 3)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toBe([]);

    $ahmad = Employee::query()->withoutGlobalScope('tenant')->where('nuptk', '1234567890123456')->sole();
    $period = EmploymentPeriod::query()->withoutGlobalScope('tenant')->where('employee_id', $ahmad->id)->sole();
    $placement = Placement::query()->withoutGlobalScope('tenant')->where('employee_id', $ahmad->id)->sole();

    expect($period->start_date->toDateString())->toBe('2020-07-01')
        ->and($period->end_date)->toBeNull()
        ->and($placement->organization_id)->toBe(CoreFixtures::node($this->tenant, 'U1-MTS')->id)
        ->and($placement->is_primary)->toBeTrue();

    $summary = DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEES_IMPORTED)->sole();

    expect(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_CREATED)->count())->toBe(3)
        ->and($summary->tenant_id)->toBe($this->tenant->id)
        ->and(json_decode((string) $summary->changes, true))->toBe(['jumlah' => 3])
        ->and(AuditAction::label(HrAuditAction::EMPLOYEES_IMPORTED))->toBe('Pegawai diimpor dari file');
});

test('tidak bisa simpan bila ada baris yang salah; tidak ada yang tersimpan dan file tetap ada', function () {
    $before = simpanImporJumlahPegawai();

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => simpanImporCsv([
        simpanImporBaris('Ahmad Fauzi'),
        simpanImporBaris('Budi Santoso', ['kode_lembaga' => 'TIDAK-ADA']),
    ])]);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasErrors(['save' => 'Ada 1 baris yang salah. Perbaiki file lalu unggah ulang; tidak ada data yang disimpan.']);

    expect(simpanImporJumlahPegawai())->toBe($before)
        ->and(Storage::disk('local')->files(PendingImportStore::DIRECTORY))->toHaveCount(1);
});

test('baris bertanda peringatan orang ganda hanya tersimpan setelah dikonfirmasi', function () {
    simpanImporPegawaiLama($this->tenant, 'Siti Aminah', birthDate: '1988-04-12');
    $personsBefore = Person::query()->count();

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => simpanImporCsv([
        simpanImporBaris('Siti Aminah', ['jenis_kelamin' => 'P', 'tanggal_lahir' => '1988-04-12']),
    ])]);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasErrors(['save' => 'Centang konfirmasi bahwa baris bertanda peringatan adalah orang yang berbeda.']);

    expect(Person::query()->count())->toBe($personsBefore);

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'), ['confirm_warnings' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.employees.index'));

    // Orang baru yang terpisah, bukan menimpa pegawai lama.
    expect(Person::query()->count())->toBe($personsBefore + 1)
        ->and(Person::query()->where('name', 'Siti Aminah')->count())->toBe(2);
});

test('file diperiksa ulang saat simpan: data yang berubah sejak pratinjau membatalkan semuanya', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => simpanImporCsv([
        simpanImporBaris('Ahmad Fauzi'),
        simpanImporBaris('Budi Santoso', ['nuptk' => '9999888877776666']),
    ])]);

    // Sesudah pratinjau, admin lain menambah pegawai dengan NUPTK yang sama.
    simpanImporPegawaiLama($this->tenant, 'Pegawai Lain', nuptk: '9999888877776666');
    $before = simpanImporJumlahPegawai();

    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasErrors('save');

    expect(simpanImporJumlahPegawai())->toBe($before)
        ->and(Employee::query()->withoutGlobalScope('tenant')->whereHas('membership.person', fn ($query) => $query->where('name', 'Ahmad Fauzi'))->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEES_IMPORTED)->exists())->toBeFalse();
});

test('simpan tanpa file impor yang sedang menunggu ditolak dengan pesan jelas', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasErrors(['save' => 'Belum ada file impor. Unggah file terlebih dahulu.']);
});

test('selain Admin SDM / Admin Yayasan tidak bisa menyimpan impor', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('hr.employees.import.save'))
        ->assertForbidden();
});

test('impor ratusan baris tersimpan dalam satu kali simpan', function () {
    $rows = [];

    for ($i = 1; $i <= 150; $i++) {
        $rows[] = simpanImporBaris("Pegawai Impor {$i}", ['nuptk' => sprintf('32%014d', $i)]);
    }

    $before = simpanImporJumlahPegawai();

    $this->actingAs($this->admin)->post(route('hr.employees.import.upload'), ['file' => simpanImporCsv($rows)]);
    $this->actingAs($this->admin)
        ->post(route('hr.employees.import.save'))
        ->assertSessionHasNoErrors();

    expect(simpanImporJumlahPegawai())->toBe($before + 150);
});
