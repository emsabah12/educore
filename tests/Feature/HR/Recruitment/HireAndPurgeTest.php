<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekrutmen HR-9b (PRD-001 §20.2; OD-HR-21): jadikan pegawai, rekap .xlsx, penghapusan otomatis 1 tahun.
 * "Sekarang" Jumat 13 Nov 2026. Lowongan Guru di MA Unit 1: Rahma (Diterima), Budi (Tidak lolos, berkas), Citra (Berkas).
 */

function rkhCatalog(mixed $test, CatalogKind $kind, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->guruPosition = rkhCatalog($this, CatalogKind::Position, 'GURU');

    $this->actingAs($this->admin)->post(route('hr.recruitment.store'), [
        'title' => 'Guru Matematika MA', 'organization_id' => $this->ma, 'position_id' => $this->guruPosition,
        'openings' => '1', 'closes_on' => '', 'requirements' => '',
    ]);
    $this->vacancy = (string) DB::table('hr_vacancies')->value('id');
    $this->post(route('hr.recruitment.open', ['vacancy' => $this->vacancy]));

    $add = fn (string $name, array $extra = []) => $this->post(route('hr.applicants.store', ['vacancy' => $this->vacancy]), array_merge([
        'name' => $name, 'gender' => 'P', 'phone' => '081234567890', 'email' => '', 'education_level' => 'S1',
        'education_major' => 'Pendidikan Matematika', 'note' => '',
    ], $extra));
    $add('Rahma Aulia');
    $path = tempnam(sys_get_temp_dir(), 'rkh');
    file_put_contents($path, "%PDF-1.4\n%%%%\n%%EOF\n");
    $add('Budi Santoso', ['gender' => 'L', 'file' => new UploadedFile($path, 'cv.pdf', null, null, true)]);
    $add('Citra');

    $this->rahma = (string) DB::table('hr_applicants')->where('name', 'Rahma Aulia')->value('id');
    $this->budi = (string) DB::table('hr_applicants')->where('name', 'Budi Santoso')->value('id');
    $step = fn (string $applicant, string $action, string $note = '') => $this->post(route('hr.applicants.step', ['vacancy' => $this->vacancy, 'applicant' => $applicant]), ['action' => $action, 'note' => $note]);
    $step($this->rahma, 'LOLOS_BERKAS');
    $step($this->rahma, 'DITERIMA');
    $step($this->budi, 'TIDAK_LOLOS', 'Ijazah tidak sesuai');
});

/**
 * @return array<string, mixed>
 */
function rkhEmployeeForm(mixed $test, array $overrides = []): array
{
    return array_merge([
        'mode' => 'baru', 'name' => 'Rahma Aulia', 'nik' => '3201014101900001', 'gender' => 'P', 'phone' => '081234567890',
        'education_level' => 'S1', 'education_major' => 'Pendidikan Matematika',
        'employment_type_id' => rkhCatalog($test, CatalogKind::EmploymentType, 'KONTRAK'), 'start_date' => '2026-11-13',
        'organization_id' => $test->ma, 'position_id' => $test->guruPosition, 'applicant_id' => $test->rahma,
    ], $overrides);
}

test('Jadikan pegawai: form terisi dari pelamar Diterima; tersimpan & tertaut sekali saja; gagal simpan tidak mengubah pelamar', function () {
    $this->get(route('hr.employees.create', ['pelamar' => $this->rahma]))->assertInertia(fn (Assert $page) => $page
        ->component('hr/employees/create')
        ->where('applicant.name', 'Rahma Aulia')
        ->where('applicant.organization_id', $this->ma)
        ->where('applicant.position_id', $this->guruPosition)
        ->where('applicant.vacancy_title', 'Guru Matematika MA'));
    // Pelamar yang belum Diterima tidak mengisi form.
    $this->get(route('hr.employees.create', ['pelamar' => $this->budi]))->assertInertia(fn (Assert $page) => $page->where('applicant', null));

    // NIK kosong → ditolak, pelamar tidak tertaut.
    $this->post(route('hr.employees.store'), rkhEmployeeForm($this, ['nik' => '']))->assertSessionHasErrors('nik');
    expect(DB::table('hr_applicants')->where('id', $this->rahma)->value('employee_id'))->toBeNull();

    // Pelamar Tidak lolos tidak bisa dijadikan pegawai.
    $this->post(route('hr.employees.store'), rkhEmployeeForm($this, ['applicant_id' => $this->budi, 'name' => 'Budi Santoso', 'gender' => 'L']))
        ->assertSessionHasErrors(['form' => 'Hanya pelamar yang Diterima yang bisa dijadikan pegawai.']);
    expect(DB::table('hr_employees')->count())->toBe(0);

    $response = $this->post(route('hr.employees.store'), rkhEmployeeForm($this));
    $employee = (string) DB::table('hr_employees')->value('id');
    $response->assertRedirect(route('hr.employees.show', ['employee' => $employee]));
    expect(DB::table('hr_applicants')->where('id', $this->rahma)->value('employee_id'))->toBe($employee)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::APPLICANT_HIRED)->count())->toBe(1);

    $this->post(route('hr.employees.store'), rkhEmployeeForm($this, ['nik' => '3201014101900002', 'name' => 'Rahma Lain']))
        ->assertSessionHasErrors(['form' => 'Pelamar ini sudah dijadikan pegawai.']);
    $this->post(route('hr.applicants.step', ['vacancy' => $this->vacancy, 'applicant' => $this->rahma]), ['action' => 'KEMBALI', 'note' => 'Salah klik tadi'])
        ->assertSessionHasErrors(['form' => 'Pelamar ini sudah dijadikan pegawai, jadi tahapnya tidak bisa diubah.']);

    $this->get(route('hr.recruitment.show', ['vacancy' => $this->vacancy]))->assertInertia(fn (Assert $page) => $page
        ->where('canHire', true)
        ->where('vacancy.hired', 1)
        ->where('applicants', fn ($applicants) => collect($applicants)->firstWhere('name', 'Rahma Aulia')['employee_id'] === $employee));
});

test('rekap .xlsx bagi pengelola; Kepala Lembaga tidak bisa mengunduh rekap', function () {
    $this->get(route('hr.recruitment.export', ['vacancy' => $this->vacancy]))->assertOk()->assertDownload('rekap-lowongan-guru-matematika-ma.xlsx');
    $this->flushSession();

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.recruitment.export', ['vacancy' => $this->vacancy]))->assertForbidden();
});

test('penghapusan otomatis: pelamar yang tidak jadi pegawai dihapus 1 tahun sesudah lowongan ditutup; jumlahnya tetap di rekap', function () {
    $this->post(route('hr.employees.store'), rkhEmployeeForm($this))->assertSessionHasNoErrors();
    $budiFile = (string) DB::table('hr_applicants')->where('id', $this->budi)->value('storage_path');
    Storage::disk('local')->assertExists($budiFile);
    $this->post(route('hr.recruitment.close', ['vacancy' => $this->vacancy]))->assertSessionHasNoErrors();

    // Belum 1 tahun → tidak ada yang dihapus.
    $this->travelTo(CarbonImmutable::parse('2027-11-12 09:00:00', BusinessClock::timezone()));
    $this->artisan('hr:purge-applicants')->assertSuccessful();
    expect(DB::table('hr_applicants')->count())->toBe(3);

    $this->travelTo(CarbonImmutable::parse('2027-11-14 09:00:00', BusinessClock::timezone()));
    $this->artisan('hr:purge-applicants')->assertSuccessful();

    expect(DB::table('hr_applicants')->pluck('name')->all())->toBe(['Rahma Aulia'])
        ->and(DB::table('hr_applicant_steps')->whereNotIn('applicant_id', [$this->rahma])->count())->toBe(0)
        ->and(json_decode((string) DB::table('hr_vacancies')->value('purged_counts'), true))->toBe(['BERKAS' => 1, 'TIDAK_LOLOS' => 1])
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::APPLICANTS_PURGED)->value('changes'))->toContain('2');
    Storage::disk('local')->assertMissing($budiFile);

    $this->flushSession();
    $this->actingAs($this->admin)->get(route('hr.recruitment.show', ['vacancy' => $this->vacancy]))->assertInertia(fn (Assert $page) => $page
        ->has('applicants', 1)
        ->where('vacancy.purged_total', 2)
        ->where('vacancy.purged.TIDAK_LOLOS', 1)
        ->where('vacancy.hired', 1));

    // Dijalankan lagi → tidak ada yang tersisa untuk dihapus.
    $this->artisan('hr:purge-applicants')->assertSuccessful();
    expect(DB::table('hr_applicants')->count())->toBe(1);
});
