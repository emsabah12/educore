<?php

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Ekspor daftar siswa per kelas (.xlsx, PRD-002 §18 P1).
 *
 * MA Unit 1, kelas X-A (wali kelas: akun `guru`) berisi Ahmad (NISN 0012345678, lahir di Bandung,
 * kontak utama ibu Siti) dan Aisyah. XI-A tanpa wali kelas. MDA Unit 1: kelas Ula 1 berisi Citra.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->mda = CoreFixtures::node($this->tenant, 'U1-MDA')->id;
    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA & MDA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    foreach ([['X-A', 10, $this->ma, $employee->id], ['XI-A', 11, $this->ma, null], ['Ula 1', 1, $this->mda, null]] as [$name, $grade, $organization, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $this->current->id, 'grade' => $grade, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    foreach ([
        ['Ahmad Fauzi', 'L', $this->ma, 'X-A', [
            'nisn' => '0012345678', 'birth_place' => 'Bandung', 'birth_date' => '2010-05-01',
            'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222']],
            'primary_guardian' => 0,
        ]],
        ['Aisyah Putri', 'P', $this->ma, 'X-A', []],
        ['Citra Lestari', 'P', $this->mda, 'Ula 1', []],
    ] as [$name, $gender, $organization, $class, $extra]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), array_merge([
            'name' => $name, 'gender' => $gender, 'organization_id' => $organization, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
            'class_id' => eksClass($class)->id,
        ], $extra))->assertSessionHasNoErrors();
    }
});

function eksClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

/**
 * Baris lembar "Siswa" dari file yang diunduh.
 *
 * @return list<list<mixed>>
 */
function eksRows(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        break;
    }

    $reader->close();
    @unlink($path);

    return $rows;
}

test('pengelola dengan izin data pribadi: daftar lengkap dengan tempat/tanggal lahir dan kontak utama', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('academic.classes.export', ['class' => eksClass('X-A')->id]))
        ->assertOk()
        ->assertDownload('daftar-siswa-x-a-'.Str::slug($this->current->name).'.xlsx');

    $rows = eksRows($response);
    $classStart = max('2025-07-14', $this->current->start_date->toDateString());

    expect($rows[0])->toBe(['No', 'Nama', 'L/P', 'NISN', 'Nomor induk', 'Tanggal masuk kelas', 'Tempat lahir', 'Tanggal lahir', 'Kontak utama', 'Hubungan', 'HP kontak utama'])
        ->and($rows)->toHaveCount(3)
        ->and((int) $rows[1][0])->toBe(1)
        ->and([$rows[1][1], $rows[1][2], $rows[1][3], $rows[1][5], $rows[1][6], $rows[1][7]])->toBe(['Ahmad Fauzi', 'L', '0012345678', $classStart, 'Bandung', '2010-05-01'])
        ->and([$rows[1][8], $rows[1][9], $rows[1][10]])->toBe(['Siti Aminah', 'Ibu', '081311112222'])
        ->and($rows[2][1])->toBe('Aisyah Putri')
        ->and($rows[2][8] ?? '')->toBe('');
});

test('tanpa izin data pribadi (Koordinator MDA): hanya kolom dasar', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $rows = eksRows($this->actingAs($koordinator)->get(route('academic.classes.export', ['class' => eksClass('Ula 1')->id]))->assertOk());

    expect($rows[0])->toBe(['No', 'Nama', 'L/P', 'NISN', 'Nomor induk', 'Tanggal masuk kelas'])
        ->and($rows[1][1])->toBe('Citra Lestari');
});

test('wali kelas dari Kelas saya: kontak utama tanpa tempat/tanggal lahir; kelas lain 404; pintu biasa 403; di luar cakupan 404', function () {
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $rows = eksRows($this->actingAs($this->guru)->get(route('academic.my-classes.export', ['class' => eksClass('X-A')->id]))->assertOk());

    expect($rows[0])->toBe(['No', 'Nama', 'L/P', 'NISN', 'Nomor induk', 'Tanggal masuk kelas', 'Kontak utama', 'Hubungan', 'HP kontak utama'])
        ->and($rows[1][6])->toBe('Siti Aminah');

    $this->actingAs($this->guru)->get(route('academic.my-classes.export', ['class' => eksClass('XI-A')->id]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.classes.export', ['class' => eksClass('X-A')->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))->get(route('academic.classes.export', ['class' => eksClass('X-A')->id]))->assertNotFound();
});
