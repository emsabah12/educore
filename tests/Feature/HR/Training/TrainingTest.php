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
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Riwayat pelatihan & sertifikasi (PRD-001 §19.1, HR-8a; OD-HR-16). "Sekarang" Rabu 11 Nov 2026.
 * Guru (akun `guru`) di MA Unit 1; Mira di MDA Unit 1 (koordinator MDA melihat pegawai tanpa data pribadi).
 */

function trnEmployee(mixed $test, string $name, string $orgCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function trnPdf(int $bytes = 200): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'trn');
    file_put_contents($path, "%PDF-1.4\n".str_repeat('%', $bytes)."\n%%EOF\n");

    return new UploadedFile($path, 'Sertifikat Diklat.pdf', null, null, true);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trnForm(array $overrides = []): array
{
    return array_merge([
        'kind' => 'PELATIHAN', 'title' => 'Diklat Kurikulum Merdeka', 'organizer' => 'Kemenag Kab. Karawang',
        'start_date' => '2026-08-03', 'end_date' => '2026-08-05', 'hours' => '32', 'certificate_number' => '123/DIKLAT/2026',
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
    $this->guruId = trnEmployee($this, 'Guru', 'U1-MA', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->mira = trnEmployee($this, 'Mira', 'U1-MDA');
});

test('Admin mencatat pelatihan (langsung diterima) dengan lampiran; tampil di tab Pelatihan & rekap tahun; unduh .xlsx', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.trainings.store', ['employee' => $this->guruId]), trnForm(['file' => trnPdf()]))
        ->assertSessionHasNoErrors();
    $this->post(route('hr.employees.trainings.store', ['employee' => $this->guruId]), trnForm([
        'kind' => 'SERTIFIKASI', 'title' => 'Sertifikasi Pendidik', 'start_date' => '2025-03-01', 'end_date' => '2025-03-01', 'hours' => '', 'valid_until' => '2026-03-01',
    ]))->assertSessionHasNoErrors();

    $row = DB::table('hr_trainings')->where('title', 'Diklat Kurikulum Merdeka')->sole();
    expect($row->status)->toBe('DITERIMA')
        ->and(str_starts_with((string) $row->storage_path, "tenants/{$this->tenant->id}/hr/trainings/"))->toBeTrue();
    Storage::disk('local')->assertExists((string) $row->storage_path);
    expect((string) DB::table('audit_logs')->where('action', HrAuditAction::TRAINING_RECORDED)->first()->changes)->not->toContain('Kurikulum');

    $this->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainings.records', 2)
            ->where('trainings.records.0.title', 'Diklat Kurikulum Merdeka')
            ->where('trainings.records.0.hours', 32)
            ->where('trainings.records.1.expired', true)
            ->where('trainings.canDownload', true)
            ->where('trainings.manage.fileMaxMb', 2));
    $this->get(route('hr.employees.trainings.download', ['employee' => $this->guruId, 'training' => $row->id]))->assertOk();

    $this->get(route('hr.trainings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/trainings/index')
            ->where('year', 2026)
            ->where('years', [2026, 2025])
            ->has('recap', 1)
            ->where('recap.0.count', 1)
            ->where('recap.0.hours', 32));
    $this->get(route('hr.trainings.index', ['tahun' => 2025]))->assertInertia(fn (Assert $page) => $page->where('recap.0.hours', 0)->where('recap.0.count', 1));
    $this->get(route('hr.trainings.export', ['tahun' => 2026]))->assertOk()->assertDownload('rekap-pelatihan-2026.xlsx');
});

test('aturan isian: tanggal selesai masa depan / sebelum mulai, jam di luar batas, berlaku sebelum selesai, nama pendek, lampiran bukan PDF', function () {
    $store = route('hr.employees.trainings.store', ['employee' => $this->guruId]);
    $this->actingAs($this->admin);

    $this->post($store, trnForm(['end_date' => '2026-11-12']))->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh di masa depan. Catat kegiatan yang sudah selesai.']);
    $this->post($store, trnForm(['end_date' => '2026-08-01']))->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
    $this->post($store, trnForm(['hours' => '1000']))->assertSessionHasErrors('hours');
    $this->post($store, trnForm(['hours' => '0']))->assertSessionHasErrors('hours');
    $this->post($store, trnForm(['valid_until' => '2026-08-04']))->assertSessionHasErrors('valid_until');
    $this->post($store, trnForm(['title' => 'ab']))->assertSessionHasErrors('title');
    $this->post($store, trnForm(['kind' => 'X']))->assertSessionHasErrors(['kind' => 'Pilih jenis kegiatan.']);

    $fake = tempnam(sys_get_temp_dir(), 'trn');
    file_put_contents($fake, 'bukan pdf');
    $this->post($store, trnForm(['file' => new UploadedFile($fake, 'sertifikat.pdf', null, null, true)]))->assertSessionHasErrors('file');
    $this->post($store, trnForm(['file' => trnPdf(3 * 1024 * 1024)]))->assertSessionHasErrors('file');

    expect(DB::table('hr_trainings')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('pegawai mengajukan sendiri → menunggu; Admin menerima / menolak (alasan tampil ke pegawai); tarik pengajuan; batalkan yang diterima', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-trainings.index'))
        ->assertInertia(fn (Assert $page) => $page->component('hr/my-trainings')->where('linked', true)->has('trainings', 0));

    $this->post(route('hr.my-trainings.store'), trnForm(['file' => trnPdf()]))->assertSessionHasNoErrors();
    $this->post(route('hr.my-trainings.store'), trnForm(['title' => 'Seminar Literasi Digital', 'kind' => 'SEMINAR']))->assertSessionHasNoErrors();
    $this->post(route('hr.my-trainings.store'), trnForm(['title' => 'Workshop yang salah', 'kind' => 'SEMINAR']))->assertSessionHasNoErrors();

    $diklat = (string) DB::table('hr_trainings')->where('title', 'Diklat Kurikulum Merdeka')->value('id');
    $seminar = (string) DB::table('hr_trainings')->where('title', 'Seminar Literasi Digital')->value('id');
    $wrong = DB::table('hr_trainings')->where('title', 'Workshop yang salah')->sole();
    expect(DB::table('hr_trainings')->where('status', 'MENUNGGU')->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::TRAINING_SUBMITTED)->count())->toBe(3);

    // Tarik pengajuan → terhapus.
    $this->post(route('hr.my-trainings.withdraw', ['training' => $wrong->id]))->assertSessionHasNoErrors();
    expect(DB::table('hr_trainings')->where('id', $wrong->id)->exists())->toBeFalse();
    $this->get(route('hr.my-trainings.download', ['training' => $diklat]))->assertOk();
    $this->flushSession();

    // Admin: daftar menunggu → terima Diklat, tolak Seminar.
    $this->actingAs($this->admin)->get(route('hr.trainings.index'))
        ->assertInertia(fn (Assert $page) => $page->has('pending', 2)->where('pending.0.employee_name', 'Guru MA & MDA'));
    $this->post(route('hr.employees.trainings.verify', ['employee' => $this->guruId, 'training' => $diklat]))->assertSessionHasNoErrors();
    $this->post(route('hr.employees.trainings.reject', ['employee' => $this->guruId, 'training' => $seminar]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('hr.employees.trainings.reject', ['employee' => $this->guruId, 'training' => $seminar]), ['reason' => 'Sertifikat belum dilampirkan'])->assertSessionHasNoErrors();
    $this->post(route('hr.employees.trainings.verify', ['employee' => $this->guruId, 'training' => $seminar]))
        ->assertSessionHasErrors(['form' => 'Pengajuan ini sudah diputuskan atau ditarik.']);
    $this->get(route('hr.trainings.index'))->assertInertia(fn (Assert $page) => $page->has('pending', 0)->where('recap.0.hours', 32));

    // Batalkan yang diterima.
    $this->post(route('hr.employees.trainings.cancel', ['employee' => $this->guruId, 'training' => $diklat]), ['reason' => 'Salah pegawai'])->assertSessionHasNoErrors();
    expect(DB::table('hr_trainings')->where('id', $diklat)->value('status'))->toBe('DIBATALKAN');
    $this->flushSession();

    // Pegawai melihat status & alasan penolakan; tidak bisa menarik yang sudah diputuskan.
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-trainings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainings', 2)
            ->where('trainings.0.status', 'DITOLAK')
            ->where('trainings.0.decision_note', 'Sertifikat belum dilampirkan'));
    $this->post(route('hr.my-trainings.withdraw', ['training' => $seminar]))
        ->assertSessionHasErrors(['form' => 'Hanya pengajuan yang masih menunggu verifikasi yang bisa ditarik.']);
});

test('hak akses: Kepala Lembaga melihat tanpa tombol & bisa unduh; koordinator tanpa data pribadi tidak bisa unduh; pengelolaan hanya Admin; catatan orang lain 404', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.trainings.store', ['employee' => $this->mira]), trnForm(['file' => trnPdf()]))->assertSessionHasNoErrors();
    $training = (string) DB::table('hr_trainings')->value('id');
    $this->flushSession();

    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->has('trainings.records', 1)->where('trainings.manage', null)->where('trainings.canDownload', true));
    $this->actingAs($kepala)->get(route('hr.employees.trainings.download', ['employee' => $this->mira, 'training' => $training]))->assertOk();
    $this->actingAs($kepala)->get(route('hr.trainings.index'))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.employees.trainings.store', ['employee' => $this->mira]), trnForm())->assertForbidden();
    $this->flushSession();

    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    $this->actingAs($koordinator)->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->has('trainings.records', 1)->where('trainings.canDownload', false));
    $this->actingAs($koordinator)->get(route('hr.employees.trainings.download', ['employee' => $this->mira, 'training' => $training]))->assertForbidden();
    $this->flushSession();

    // Pegawai lain lewat Pelatihan saya → 404.
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-trainings.download', ['training' => $training]))->assertNotFound();
    $this->actingAs($this->guru)->post(route('hr.my-trainings.withdraw', ['training' => $training]))->assertSessionHasErrors(['form' => 'Catatan pelatihan tidak ditemukan.']);
});
