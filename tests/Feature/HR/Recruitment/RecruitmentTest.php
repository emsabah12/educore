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
 * Rekrutmen HR-9a (PRD-001 §20.1; OD-HR-19, OD-HR-20). "Sekarang" Jumat 13 Nov 2026.
 * Lowongan Guru Matematika di MA Unit 1 (cakupan Kepala Unit 1) dan Guru Produktif di SMK Unit 3 (di luar Unit 1).
 */

function rktPdf(int $bytes = 200): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rkt');
    file_put_contents($path, "%PDF-1.4\n".str_repeat('%', $bytes)."\n%%EOF\n");

    return new UploadedFile($path, 'CV Rahma.pdf', null, null, true);
}

/**
 * @return array<string, mixed>
 */
function rktVacancy(mixed $test, string $orgCode, array $overrides = []): array
{
    return array_merge([
        'title' => 'Guru Matematika MA',
        'organization_id' => CoreFixtures::node($test->tenant, $orgCode)->id,
        'position_id' => (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', 'GURU')->value('id'),
        'openings' => '1',
        'closes_on' => '2026-11-30',
        'requirements' => "S1 Pendidikan Matematika\nBisa mengajar kitab",
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function rktApplicant(array $overrides = []): array
{
    return array_merge([
        'name' => 'Rahma Aulia', 'gender' => 'P', 'phone' => '0812-3456-7890', 'email' => 'Rahma@Contoh.id',
        'education_level' => 'S1', 'education_major' => 'Pendidikan Matematika', 'note' => 'Lamaran lewat WA',
    ], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->kepala = DevAccounts::user('kepala.unit1');
});

test('Admin membuat lowongan (Draf), membuka, menambah pelamar dengan berkas, mengubah, lalu menutup; Draf kosong bisa dihapus', function () {
    $this->actingAs($this->admin);

    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA', ['title' => 'ab']))->assertSessionHasErrors('title');
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA', ['openings' => '0']))->assertSessionHasErrors('openings');
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA'))->assertRedirect();
    $vacancy = (string) DB::table('hr_vacancies')->value('id');
    expect(DB::table('hr_vacancies')->value('status'))->toBe('DRAF');

    $store = route('hr.applicants.store', ['vacancy' => $vacancy]);
    $this->post($store, rktApplicant())->assertSessionHasErrors(['form' => 'Pelamar hanya bisa ditambahkan ke lowongan yang sedang dibuka.']);

    $this->post(route('hr.recruitment.open', ['vacancy' => $vacancy]))->assertSessionHasNoErrors();
    $this->post($store, rktApplicant(['phone' => '12']))->assertSessionHasErrors('phone');
    $this->post($store, rktApplicant(['email' => 'bukan-email']))->assertSessionHasErrors('email');
    $this->post($store, rktApplicant(['gender' => 'X']))->assertSessionHasErrors('gender');
    $this->post($store, rktApplicant(['file' => rktPdf()]))->assertSessionHasNoErrors();

    $applicant = DB::table('hr_applicants')->sole();
    expect($applicant->stage)->toBe('BERKAS')
        ->and($applicant->phone)->toBe('081234567890')
        ->and($applicant->email)->toBe('rahma@contoh.id');
    Storage::disk('local')->assertExists((string) $applicant->storage_path);
    expect((string) DB::table('audit_logs')->where('action', HrAuditAction::APPLICANT_ADDED)->value('changes'))->not->toContain('Rahma');

    // Ganti berkas → berkas lama terhapus.
    $this->post(route('hr.applicants.update', ['vacancy' => $vacancy, 'applicant' => $applicant->id]), rktApplicant(['name' => 'Rahma Aulia Putri', 'file' => rktPdf(300)]))
        ->assertSessionHasNoErrors();
    $updated = DB::table('hr_applicants')->sole();
    expect($updated->name)->toBe('Rahma Aulia Putri')->and($updated->storage_path)->not->toBe($applicant->storage_path);
    Storage::disk('local')->assertMissing((string) $applicant->storage_path);
    Storage::disk('local')->assertExists((string) $updated->storage_path);
    $this->get(route('hr.applicants.download', ['vacancy' => $vacancy, 'applicant' => $applicant->id]))->assertOk();

    $this->get(route('hr.recruitment.show', ['vacancy' => $vacancy]))->assertInertia(fn (Assert $page) => $page
        ->component('hr/recruitment/show')
        ->where('vacancy.status', 'DIBUKA')
        ->where('vacancy.counts.BERKAS', 1)
        ->has('applicants', 1)
        ->where('applicants.0.file_name', 'CV Rahma.pdf')
        ->where('applicants.0.education_label', fn ($label) => is_string($label)));

    $this->post(route('hr.recruitment.close', ['vacancy' => $vacancy]))->assertSessionHasNoErrors();
    $this->post($store, rktApplicant(['name' => 'Telat']))->assertSessionHasErrors('form');
    $this->delete(route('hr.recruitment.destroy', ['vacancy' => $vacancy]))->assertSessionHasErrors(['form' => 'Hanya lowongan berstatus Draf yang bisa dihapus.']);

    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U3-SMK', ['title' => 'Staf TU']));
    $draft = (string) DB::table('hr_vacancies')->where('title', 'Staf TU')->value('id');
    $this->delete(route('hr.recruitment.destroy', ['vacancy' => $draft]))->assertRedirect(route('hr.recruitment.index'));
    expect(DB::table('hr_vacancies')->count())->toBe(1);

    $this->get(route('hr.recruitment.index'))->assertInertia(fn (Assert $page) => $page
        ->component('hr/recruitment/index')
        ->where('canManage', true)
        ->has('vacancies', 1)
        ->where('vacancies.0.total', 1));
});

test('tahap seleksi: lolos berkas → tes → diterima; tidak lolos & kembalikan wajib alasan; urutan tahap dijaga', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA'));
    $vacancy = (string) DB::table('hr_vacancies')->value('id');
    $this->post(route('hr.recruitment.open', ['vacancy' => $vacancy]));
    $this->post(route('hr.applicants.store', ['vacancy' => $vacancy]), rktApplicant());
    $this->post(route('hr.applicants.store', ['vacancy' => $vacancy]), rktApplicant(['name' => 'Budi', 'gender' => 'L', 'phone' => '', 'email' => '']));
    $rahma = (string) DB::table('hr_applicants')->where('name', 'Rahma Aulia')->value('id');
    $budi = (string) DB::table('hr_applicants')->where('name', 'Budi')->value('id');
    $step = fn (string $applicant, array $data) => $this->post(route('hr.applicants.step', ['vacancy' => $vacancy, 'applicant' => $applicant]), $data);

    $step($rahma, ['action' => 'DITERIMA'])->assertSessionHasErrors(['form' => 'Pelamar hanya bisa diterima dari tahap Tes/wawancara.']);
    $step($rahma, ['action' => 'LOLOS_BERKAS', 'score' => '101'])->assertSessionHasErrors('score');
    $step($rahma, ['action' => 'LOLOS_BERKAS', 'score' => '85', 'note' => 'Berkas lengkap'])->assertSessionHasNoErrors();
    $step($rahma, ['action' => 'CATATAN', 'score' => '', 'note' => 'Microteaching baik'])->assertSessionHasNoErrors();
    $step($rahma, ['action' => 'DITERIMA', 'score' => '90'])->assertSessionHasNoErrors();
    expect(DB::table('hr_applicants')->where('id', $rahma)->value('stage'))->toBe('DITERIMA');

    $step($budi, ['action' => 'TIDAK_LOLOS', 'note' => ''])->assertSessionHasErrors('note');
    $step($budi, ['action' => 'TIDAK_LOLOS', 'note' => 'Ijazah tidak sesuai'])->assertSessionHasNoErrors();
    $step($budi, ['action' => 'CATATAN', 'note' => 'Coba'])->assertSessionHasErrors('form');
    $this->post(route('hr.applicants.update', ['vacancy' => $vacancy, 'applicant' => $budi]), rktApplicant(['name' => 'Budi S']))
        ->assertSessionHasErrors(['form' => 'Data pelamar yang sudah Diterima/Tidak lolos tidak bisa diubah.']);

    $step($budi, ['action' => 'KEMBALI', 'note' => 'Salah klik, ijazah ternyata sesuai'])->assertSessionHasNoErrors();
    expect(DB::table('hr_applicants')->where('id', $budi)->value('stage'))->toBe('TES');

    $this->get(route('hr.recruitment.show', ['vacancy' => $vacancy]))->assertInertia(fn (Assert $page) => $page
        ->where('applicants.0.name', 'Budi')
        ->where('applicants.0.stage', 'TES')
        ->has('applicants.0.steps', 2)
        ->where('applicants.1.name', 'Rahma Aulia')
        ->has('applicants.1.steps', 3)
        ->where('applicants.1.steps.0.score', 85)
        ->where('applicants.1.steps.0.by', 'Admin Yayasan')
        ->where('vacancy.counts.DITERIMA', 1));

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::APPLICANT_STEP)->pluck('changes')->implode(' ');
    expect($audit)->not->toContain('Ijazah')->not->toContain('Microteaching');
});

test('Kepala Lembaga: lihat lowongan lembaganya, catatan wawancara di tahap Tes saja, tidak bisa memindah tahap; lowongan lain 404; staf 403', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA'));
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U3-SMK', ['title' => 'Guru Produktif']));
    $ma = (string) DB::table('hr_vacancies')->where('title', 'Guru Matematika MA')->value('id');
    $smk = (string) DB::table('hr_vacancies')->where('title', 'Guru Produktif')->value('id');
    $this->post(route('hr.recruitment.open', ['vacancy' => $ma]));
    $this->post(route('hr.applicants.store', ['vacancy' => $ma]), rktApplicant(['file' => rktPdf()]));
    $applicant = (string) DB::table('hr_applicants')->value('id');
    $this->flushSession();

    $this->actingAs($this->kepala)->get(route('hr.recruitment.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canManage', false)
        ->has('vacancies', 1)
        ->where('vacancies.0.title', 'Guru Matematika MA'));
    $this->get(route('hr.recruitment.show', ['vacancy' => $smk]))->assertNotFound();
    $this->get(route('hr.applicants.download', ['vacancy' => $ma, 'applicant' => $applicant]))->assertOk();

    $step = route('hr.applicants.step', ['vacancy' => $ma, 'applicant' => $applicant]);
    $this->post($step, ['action' => 'CATATAN', 'note' => 'Belum diwawancara'])->assertSessionHasErrors(['form' => 'Catatan wawancara hanya bisa ditambah pada tahap Tes/wawancara.']);
    $this->post($step, ['action' => 'LOLOS_BERKAS'])->assertSessionHasErrors(['form' => 'Anda hanya bisa menambah catatan wawancara.']);
    $this->post(route('hr.recruitment.store'), rktVacancy($this, 'U1-MA'))->assertForbidden();
    $this->post(route('hr.applicants.store', ['vacancy' => $ma]), rktApplicant())->assertForbidden();
    $this->flushSession();

    $this->actingAs($this->admin)->post($step, ['action' => 'LOLOS_BERKAS']);
    $this->flushSession();

    $this->actingAs($this->kepala)->post($step, ['action' => 'CATATAN', 'score' => '80', 'note' => 'Komunikasi baik'])->assertSessionHasNoErrors();
    expect(DB::table('hr_applicant_steps')->where('action', 'CATATAN')->value('note'))->toBe('Komunikasi baik');
    $this->flushSession();

    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('hr.recruitment.index'))->assertForbidden();
});
