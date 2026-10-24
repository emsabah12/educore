<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Academic\Application\Import\StudentImportReader;
use Modules\Academic\Application\Import\StudentImportRow;
use Modules\Academic\Application\Import\StudentImportValidator;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Student\StudentGuardian;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Impor siswa: kolom NIK siswa & NIK ayah/ibu/wali (AC-1g-b, PRD-002 §4.5, OD-AC-02 revisi).
 *
 * Data uji: Ahmad Fauzi — MDA Unit 3, NISN 0012345678, NIK 3201011204120001, ibu Siti Aminah
 * (NIK 3201015505800003).
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

    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => CoreFixtures::node($this->tenant, 'U3-MDA')->id,
        'start_date' => '2025-07-14', 'entry_type' => 'BARU', 'nisn' => '0012345678', 'birth_date' => '2012-04-12',
        'nik' => '3201011204120001',
        'guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'phone' => '081311112222', 'nik' => '3201015505800003']],
        'primary_guardian' => 0,
    ])->assertSessionHasNoErrors();
});

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function sniBaris(array $override = []): array
{
    return array_merge(['nama' => 'Citra Lestari', 'jenis_kelamin' => 'P', 'tanggal_masuk' => '2025-07-14'], $override);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<StudentImportRow>
 */
function sniPeriksa(Tenant $tenant, Organization $organization, array $rows): array
{
    $lines = [];

    foreach ($rows as $index => $values) {
        $lines[] = ['line' => $index + 2, 'values' => $values];
    }

    return app(StudentImportValidator::class)->validate($tenant->id, $organization, true, $lines)->rows;
}

/**
 * @return list<string>
 */
function sniPesan(StudentImportRow $row, string $column): array
{
    return array_values(array_map(fn (array $error): string => $error['message'], array_filter($row->errors, fn (array $error): bool => $error['column'] === $column)));
}

function sniMembership(Tenant $tenant, string $name): Membership
{
    return Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();
}

test('NIK siswa yang cocok dengan nama = siswa lama; nama lain, yayasan lain, dobel, atau angka Excel = Salah; tidak cocok tanggal lahir = Peringatan', function () {
    $luar = Person::query()->create(['name' => 'Orang Luar']);
    app(GrantMembership::class)->handle($luar->id, CoreFixtures::tenant('YYS-B')->id);
    app(PersonNik::class)->assign($luar->id, '3201014101100009');

    $rows = sniPeriksa($this->tenant, $this->smp, [
        sniBaris(['nama' => 'ahmad  fauzi', 'jenis_kelamin' => 'L', 'nik' => '3201 0112 0412 0001']),
        sniBaris(['nik' => '3201014101100009']),
        sniBaris(['nama' => 'Dewi', 'nik' => '3201014101100010']),
        sniBaris(['nama' => 'Eka', 'nik' => '3201014101100010']),
        sniBaris(['nama' => 'Fitri', 'nik' => '3201014101100011', 'tanggal_lahir' => '2011-01-02']),
        sniBaris(['nama' => 'Gita', 'nik' => '12345']),
    ]);

    expect($rows[0]->mode)->toBe(StudentImportRow::MODE_EXISTING)
        ->and($rows[0]->status())->toBe(StudentImportRow::READY)
        ->and($rows[0]->data?->existingMembershipId)->toBe(sniMembership($this->tenant, 'Ahmad Fauzi')->id)
        ->and(sniPesan($rows[1], 'nik'))->toBe(['NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.'])
        ->and($rows[2]->status())->toBe(StudentImportRow::READY)
        ->and(sniPesan($rows[3], 'nik'))->toBe(['Sama dengan baris 4.'])
        ->and($rows[4]->warnings)->toBe(['NIK tidak cocok dengan tanggal lahir. Periksa kembali bila perlu.'])
        ->and(sniPesan($rows[5], 'nik'))->toBe(['Harus 16 angka.']);

    $other = sniPeriksa($this->tenant, $this->smp, [sniBaris(['nama' => 'Bukan Ahmad', 'jenis_kelamin' => 'L', 'nik' => '3201011204120001'])])[0];

    expect(sniPesan($other, 'nik'))->toBe(['NIK ini sudah tercatat untuk orang lain dengan nama berbeda. Periksa lagi NIK atau namanya.']);

    $path = tempnam(sys_get_temp_dir(), 'impor-nik-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['nama', 'jenis_kelamin', 'tanggal_masuk', 'nik']));
    $writer->addRow(Row::fromValues(['Hana', 'P', '2025-07-14', 3201014101100012]));
    $writer->close();

    $read = app(StudentImportReader::class)->read($path, 'xlsx');
    $row = app(StudentImportValidator::class)->validate($this->tenant->id, $this->smp, true, $read)->rows[0];

    expect(sniPesan($row, 'nik'))->toBe(['Tersimpan sebagai angka sehingga digitnya bisa berubah. Ubah format sel menjadi Teks lalu ketik ulang.']);

    @unlink($path);
});

test('NIK berbeda dari yang tercatat untuk siswa lama (lewat NISN) ditolak', function () {
    $periksa = fn (string $nik): StudentImportRow => sniPeriksa($this->tenant, $this->smp, [
        sniBaris(['nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L', 'nisn' => '0012345678', 'nik' => $nik]),
    ])[0];
    $rows = [$periksa('3201011204120002'), $periksa('3201015505800003')];

    expect(sniPesan($rows[0], 'nik'))->toBe(['NIK berbeda dengan yang sudah tercatat untuk siswa ini. Kosongkan bila tidak ingin mengubahnya.'])
        ->and(sniPesan($rows[1], 'nik'))->toBe(['NIK ini milik orang lain, bukan siswa dengan NISN tersebut. Periksa lagi NIK atau NISN-nya.']);
});

test('NIK orang tua: milik orang di luar yayasan, sama dengan NIK siswa, atau dobel di baris = Salah', function () {
    $luar = Person::query()->create(['name' => 'Orang Luar']);
    app(GrantMembership::class)->handle($luar->id, CoreFixtures::tenant('YYS-B')->id);
    app(PersonNik::class)->assign($luar->id, '3201010101800009');

    $rows = sniPeriksa($this->tenant, $this->smp, [
        sniBaris(['nama_ayah' => 'Hasan', 'nik_ayah' => '3201010101800009']),
        sniBaris(['nama' => 'Dewi', 'nik' => '3201014101100010', 'nama_wali' => 'Paman', 'nik_wali' => '3201014101100010']),
        sniBaris(['nama' => 'Eka', 'nama_ayah' => 'Hasan', 'nik_ayah' => '3201010101800001', 'nama_ibu' => 'Rina', 'nik_ibu' => '3201010101800001']),
        sniBaris(['nama' => 'Fitri', 'nik_ibu' => '3201015505800003']),
    ]);

    expect(sniPesan($rows[0], 'nik_ayah'))->toBe(['NIK ini sudah terdaftar di EduCore atas nama orang lain. Periksa kembali; bila NIK benar, hubungi admin platform.'])
        ->and(sniPesan($rows[1], 'nik_wali'))->toBe(['Sama dengan NIK siswa di baris ini.'])
        ->and(sniPesan($rows[2], 'nik_ibu'))->toBe(['Sama dengan NIK ayah di baris ini.'])
        ->and(sniPesan($rows[3], 'nama_ibu'))->toBe(['NIK diisi, tetapi namanya kosong.']);
});

test('simpan: NIK siswa & orang tua tersimpan; ibu dengan NIK tercatat dipakai ulang; siswa lama lewat NIK hanya didaftarkan', function () {
    $persons = Person::query()->count();

    $this->actingAs($this->operator)->post(route('academic.students.import.upload'), [
        'organization_id' => $this->smp->id,
        'file' => UploadedFile::fake()->createWithContent('siswa.csv', implode("\r\n", [
            'nama;jenis_kelamin;tanggal_masuk;nik;nama_ayah;nik_ayah;nama_ibu;nik_ibu;kontak_utama',
            // Adik Ahmad: ibu (NIK sama) dipakai ulang walau namanya ditulis beda; ayah orang baru.
            'Aisyah Fauzi;P;2025-07-14;3201015203140004;Hasan Fauzi;3201010101800001;Ibu Siti;3201015505800003;IBU',
            // Ahmad sendiri: dikenali lewat NIK + nama, hanya didaftarkan ke SMP.
            'Ahmad Fauzi;L;2025-07-14;3201011204120001;;;;;',
        ])."\r\n"),
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->post(route('academic.students.import.save'))->assertSessionHasNoErrors();

    $siti = sniMembership($this->tenant, 'Siti Aminah');
    $nik = app(PersonNik::class);

    // Orang baru hanya Aisyah dan Hasan.
    expect(Person::query()->count())->toBe($persons + 2)
        ->and($nik->valueOf(sniMembership($this->tenant, 'Aisyah Fauzi')->person_id))->toBe('3201015203140004')
        ->and($nik->valueOf(sniMembership($this->tenant, 'Hasan Fauzi')->person_id))->toBe('3201010101800001')
        ->and(StudentGuardian::query()->withoutGlobalScope('tenant')->where('guardian_membership_id', $siti->id)->count())->toBe(2)
        ->and(DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->count())->toBe(2);

    $audit = DB::table('audit_logs')->pluck('changes')->implode(' ');

    expect($audit)->not->toContain('3201015203140004')->and($audit)->not->toContain('3201010101800001');
});
