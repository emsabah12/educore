<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Format rapor per lembaga (PRD-002 §22.5 AC-8c): kop berlogo, judul, sebutan peserta didik,
 * tempat & tanggal Hijriah, penanda tangan. Diatur Operator Akademik lembaga itu & Admin Yayasan.
 *
 * "Hari ini" 22 Okt 2026. MA Unit 1, kelas X-A berisi Ahmad (rapor dicetak tanpa nilai final).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA');
});

/**
 * Gambar PNG 1×1 piksel yang sah (jenis file dibaca dari isinya).
 */
function rfPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rfForm(array $overrides = []): array
{
    return array_merge([
        'header_lines' => ['PONDOK PESANTREN  ASSHIDDIQIYAH', 'Madrasah Diniyah Asshiddiqiyah 4', 'Jl. Singaperbangsa Pasirukem', 'Kode Pos 41384'],
        'title' => 'LAPORAN HASIL BELAJAR SANTRI',
        'student_term' => 'SANTRI',
        'place' => 'Karawang',
        'show_hijri' => '1',
        'principal_title' => 'Kepala Madrasah',
        'principal_name' => 'Muhammad Hamidi',
        'witness_title' => "Khodimul Ma'had",
        'witness_name' => 'K.H. Hasan Nuri Hidayatullah',
    ], $overrides);
}

test('format bawaan: lembaga belum diatur memakai judul & nama lembaga bawaan', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.report-formats.index', ['lembaga' => $this->ma->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/grades/report-format')
            ->where('filters.lembaga', $this->ma->id)
            ->where('format.configured', false)
            ->where('format.header_lines.1', $this->ma->name)
            ->where('format.title', 'LAPORAN HASIL BELAJAR')
            ->where('format.principal_title', 'Kepala lembaga')
            ->where('logo', null),
        );
});

test('simpan format + logo: tersimpan rapi, logo di disk privat, tampil di rapor cetak sebagai data URI; ganti & hapus logo', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['logo' => rfPng()]))
        ->assertSessionHasNoErrors();

    $row = DB::table('ac_report_formats')->where('organization_id', $this->ma->id)->first();
    expect($row->header_line1)->toBe('PONDOK PESANTREN ASSHIDDIQIYAH')
        ->and($row->student_term)->toBe('SANTRI')
        ->and((bool) $row->show_hijri)->toBeTrue()
        ->and($row->logo_mime)->toBe('image/png')
        ->and(str_starts_with((string) $row->logo_path, "tenants/{$this->tenant->id}/academic/report-logos/"))->toBeTrue();
    Storage::disk('local')->assertExists((string) $row->logo_path);
    expect(DB::table('audit_logs')->where('action', AcademicAuditAction::REPORT_FORMAT_SAVED)->exists())->toBeTrue();

    // Rapor cetak kelas MA membawa format & logo.
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $term = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $year->id)->where('sequence', 1)->sole();
    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma->id, 'academic_year_id' => $year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $class = SchoolClass::query()->withoutGlobalScope('tenant')->sole();
    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->ma->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU', 'class_id' => $class->id,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $class->id, 'semester' => $term->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('format.configured', true)
            ->where('format.title', 'LAPORAN HASIL BELAJAR SANTRI')
            ->where('format.witness_title', "Khodimul Ma'had")
            ->where('format.logo', fn (string $logo) => str_starts_with($logo, 'data:image/png;base64,')),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.reports.index', ['class' => $class->id, 'semester' => $term->id]))
        ->assertInertia(fn (Assert $page) => $page->where('formatUrl', "/akademik/format-rapor?lembaga={$this->ma->id}"));

    // Ganti logo: file lama dihapus.
    $old = (string) $row->logo_path;
    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['logo' => rfPng()]))
        ->assertSessionHasNoErrors();
    $new = (string) DB::table('ac_report_formats')->where('organization_id', $this->ma->id)->value('logo_path');
    expect($new)->not->toBe($old);
    Storage::disk('local')->assertMissing($old);

    // Hapus logo.
    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['remove_logo' => '1']))
        ->assertSessionHasNoErrors();
    expect(DB::table('ac_report_formats')->where('organization_id', $this->ma->id)->value('logo_path'))->toBeNull();
    Storage::disk('local')->assertMissing($new);
});

test('validasi: nama "Mengetahui" tanpa jabatan, logo bukan gambar, sebutan tak dikenal', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['witness_title' => '']))
        ->assertSessionHasErrors(['witness_title' => 'Isi jabatan penanda tangan "Mengetahui" (mis. Khodimul Ma\'had).']);

    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['logo' => UploadedFile::fake()->createWithContent('logo.png', 'bukan gambar')]))
        ->assertSessionHasErrors(['logo' => 'Logo harus berupa gambar PNG atau JPG.']);

    $this->actingAs($this->admin)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm(['student_term' => 'PELAJAR']))
        ->assertSessionHasErrors(['student_term' => 'Pilih sebutan peserta didik: Siswa, Santri, atau Murid.']);

    expect(DB::table('ac_report_formats')->count())->toBe(0);
});

test('hak akses: Operator Akademik hanya lembaganya (lembaga lain 404), pemantau tanpa izin kelola 403', function () {
    $operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $smp = CoreFixtures::node($this->tenant, AcademicDevSeeder::OPERATOR_ORGANIZATION);

    $this->actingAs($operator)
        ->get(route('academic.report-formats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('options.organizations', 1)
            ->where('options.organizations.0.value', $smp->id),
        );

    $this->actingAs($operator)
        ->post(route('academic.report-formats.update', ['organization' => $smp->id]), rfForm())
        ->assertSessionHasNoErrors();

    $this->actingAs($operator)
        ->post(route('academic.report-formats.update', ['organization' => $this->ma->id]), rfForm())
        ->assertNotFound();

    // Pimpinan pondok hanya melihat (tanpa izin kelola kelas).
    $this->actingAs(DevAccounts::user('pimpinan.ponpes'))
        ->get(route('academic.report-formats.index'))
        ->assertForbidden();
});
