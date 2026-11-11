<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Discipline\ManageDiscipline;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Catatan disiplin pegawai (PRD-001 §18.1, HR-7a; OD-HR-13, OD-HR-14). "Sekarang" Rabu 11 Nov 2026.
 * Guru (akun `guru`) di MA Unit 1 (cakupan Kepala Unit 1); Mira di MDA Unit 1; Sari di SMK Unit 3.
 */

function dspEmployee(mixed $test, string $name, string $orgCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function dspPdf(int $bytes = 200): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'dsp');
    file_put_contents($path, "%PDF-1.4\n".str_repeat('%', $bytes)."\n%%EOF\n");

    return new UploadedFile($path, 'SP1 Guru.pdf', null, null, true);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dspForm(array $overrides = []): array
{
    return array_merge([
        'kind' => 'SP1', 'issued_on' => '2026-11-11', 'violation' => 'Terlambat masuk kelas 5 kali dalam sebulan',
        'follow_up' => 'Pembinaan oleh kepala madrasah', 'valid_until' => '2027-05-10',
    ], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->guruId = dspEmployee($this, 'Guru', 'U1-MA', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->mira = dspEmployee($this, 'Mira', 'U1-MDA');
    $this->sari = dspEmployee($this, 'Sari', 'U3-SMK');
});

test('catat SP dengan lampiran: disk privat, audit tanpa isi, tampil di tab Disiplin; status Berlaku, Kedaluwarsa, Tercatat', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm(['file' => dspPdf()]))
        ->assertSessionHasNoErrors();

    $record = DB::table('hr_discipline_records')->sole();
    expect($record->kind)->toBe('SP1')
        ->and(str_starts_with((string) $record->storage_path, "tenants/{$this->tenant->id}/hr/discipline/"))->toBeTrue()
        ->and($record->mime_type)->toBe('application/pdf');
    Storage::disk('local')->assertExists((string) $record->storage_path);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::DISCIPLINE_RECORDED)->sole();
    expect((string) $audit->changes)->not->toContain('Terlambat')->toContain('SP1');

    // Teguran lisan tanpa masa berlaku; teguran tertulis yang sudah lewat.
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm(['kind' => 'TEGURAN_LISAN', 'issued_on' => '2026-03-01', 'valid_until' => '', 'follow_up' => '']))
        ->assertSessionHasNoErrors();
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm(['kind' => 'TEGURAN_TERTULIS', 'issued_on' => '2026-04-01', 'valid_until' => '2026-10-31']))
        ->assertSessionHasNoErrors();

    $this->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('discipline.records', 3)
            ->where('discipline.records.0.kind_label', 'SP1')
            ->where('discipline.records.0.status', 'BERLAKU')
            ->where('discipline.records.0.file_name', 'SP1 Guru.pdf')
            ->where('discipline.records.1.status', 'KEDALUWARSA')
            ->where('discipline.records.2.status', 'TERCATAT')
            ->where('discipline.manage.validMonths', 6));

    $this->get(route('hr.employees.discipline.download', ['employee' => $this->guruId, 'record' => $record->id]))->assertOk();
});

test('aturan isian: SP wajib berlaku sampai, tanggal masa depan, berlaku sebelum tanggal, uraian pendek, lampiran bukan PDF/gambar', function () {
    $store = route('hr.employees.discipline.store', ['employee' => $this->guruId]);
    $this->actingAs($this->admin);

    $this->post($store, dspForm(['valid_until' => '']))->assertSessionHasErrors(['valid_until' => 'Isi tanggal berlaku sampai untuk SP1.']);
    $this->post($store, dspForm(['issued_on' => '2026-11-12']))->assertSessionHasErrors(['issued_on' => 'Tanggal tidak boleh di masa depan.']);
    $this->post($store, dspForm(['valid_until' => '2026-11-10']))->assertSessionHasErrors(['valid_until' => 'Tanggal berlaku sampai tidak boleh sebelum tanggal catatan.']);
    $this->post($store, dspForm(['violation' => 'telat']))->assertSessionHasNoErrors();
    $this->post($store, dspForm(['violation' => 'abc']))->assertSessionHasErrors('violation');
    $this->post($store, dspForm(['kind' => 'SP9']))->assertSessionHasErrors(['kind' => 'Pilih jenis catatan disiplin.']);

    $fake = tempnam(sys_get_temp_dir(), 'dsp');
    file_put_contents($fake, 'bukan pdf');
    $this->post($store, dspForm(['file' => new UploadedFile($fake, 'surat.pdf', null, null, true)]))->assertSessionHasErrors('file');
    $this->post($store, dspForm(['file' => dspPdf(3 * 1024 * 1024)]))->assertSessionHasErrors('file');

    expect(DB::table('hr_discipline_records')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    expect(ManageDiscipline::defaultValidUntil('2026-11-11'))->toBe('2027-05-10')
        ->and(ManageDiscipline::defaultValidUntil('2026-08-31'))->toBe('2027-02-27');
});

test('cabut: alasan wajib, tetap tersimpan sebagai riwayat, tidak bisa dicabut dua kali; daftar Disiplin pegawai & saringan', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm())->assertSessionHasNoErrors();
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->sari]), dspForm(['kind' => 'SP2']))->assertSessionHasNoErrors();
    $record = (string) DB::table('hr_discipline_records')->where('employee_id', $this->guruId)->value('id');
    $revoke = route('hr.employees.discipline.revoke', ['employee' => $this->guruId, 'record' => $record]);

    $this->post($revoke, ['revoke_reason' => ''])->assertSessionHasErrors(['revoke_reason' => 'Tulis alasan pencabutan (5–300 karakter).']);
    $this->post($revoke, ['revoke_reason' => 'Salah input pegawai'])->assertSessionHasNoErrors();
    $this->post($revoke, ['revoke_reason' => 'Salah input pegawai'])->assertSessionHasErrors(['form' => 'Catatan ini sudah dicabut.']);

    expect(DB::table('hr_discipline_records')->where('id', $record)->value('revoke_reason'))->toBe('Salah input pegawai')
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::DISCIPLINE_REVOKED)->count())->toBe(1);

    // Catatan Sari lewat URL pegawai lain → tidak ditemukan.
    $sariRecord = (string) DB::table('hr_discipline_records')->where('employee_id', $this->sari)->value('id');
    $this->post(route('hr.employees.discipline.revoke', ['employee' => $this->guruId, 'record' => $sariRecord]), ['revoke_reason' => 'Coba-coba'])
        ->assertSessionHasErrors(['form' => 'Catatan disiplin tidak ditemukan.']);

    $this->get(route('hr.discipline.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/discipline/index')
            ->where('filters.status', 'berlaku')
            ->has('records.data', 1)
            ->where('records.data.0.employee_name', 'Sari')
            ->where('records.data.0.kind_label', 'SP2'));
    $this->get(route('hr.discipline.index', ['status' => 'semua']))->assertInertia(fn (Assert $page) => $page->has('records.data', 2));
    $this->get(route('hr.discipline.index', ['status' => 'semua', 'jenis' => 'SP1']))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.status', 'DICABUT'));
    $this->get(route('hr.discipline.index', ['status' => 'semua', 'lembaga' => CoreFixtures::node($this->tenant, 'U3-SMK')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.employee_name', 'Sari'));
});

test('hak akses: Kepala Lembaga hanya melihat pegawai lembaganya & tidak bisa mencatat; koordinator tanpa izin; pegawai melihat catatannya di Dokumen saya', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm(['file' => dspPdf()]))->assertSessionHasNoErrors();
    $this->post(route('hr.employees.discipline.store', ['employee' => $this->sari]), dspForm(['kind' => 'SP2']))->assertSessionHasNoErrors();
    $guruRecord = (string) DB::table('hr_discipline_records')->where('employee_id', $this->guruId)->value('id');
    $sariRecord = (string) DB::table('hr_discipline_records')->where('employee_id', $this->sari)->value('id');
    $this->flushSession();

    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.discipline.index'))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.employee_name', 'Guru MA & MDA'));
    $this->actingAs($kepala)->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page->has('discipline.records', 1)->where('discipline.manage', null));
    $this->actingAs($kepala)->get(route('hr.employees.discipline.download', ['employee' => $this->guruId, 'record' => $guruRecord]))->assertOk();
    $this->actingAs($kepala)->get(route('hr.employees.discipline.download', ['employee' => $this->sari, 'record' => $sariRecord]))->assertNotFound();
    $this->actingAs($kepala)->post(route('hr.employees.discipline.store', ['employee' => $this->guruId]), dspForm())->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.employees.discipline.revoke', ['employee' => $this->guruId, 'record' => $guruRecord]), ['revoke_reason' => 'Coba-coba'])->assertForbidden();
    $this->flushSession();

    // Koordinator MDA: melihat pegawai tetapi tanpa izin disiplin.
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    $this->actingAs($koordinator)->get(route('hr.discipline.index'))->assertForbidden();
    $this->actingAs($koordinator)->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->where('discipline', null));
    $this->flushSession();

    // Pegawai sendiri: Dokumen saya → Catatan disiplin + unduh lampirannya; catatan orang lain 404.
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-documents.index'))
        ->assertInertia(fn (Assert $page) => $page->has('discipline', 1)->where('discipline.0.kind_label', 'SP1')->where('discipline.0.violation', 'Terlambat masuk kelas 5 kali dalam sebulan'));
    $this->actingAs($this->guru)->get(route('hr.my-documents.discipline.download', ['record' => $guruRecord]))->assertOk();
    $this->actingAs($this->guru)->get(route('hr.my-documents.discipline.download', ['record' => $sariRecord]))->assertNotFound();
});
