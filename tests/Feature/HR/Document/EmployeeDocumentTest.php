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
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\DocumentType;
use Modules\HR\Domain\Document\EmployeeDocument;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Dokumen & SK pegawai (PRD-001 §15, HR-2a). "Sekarang" 24 Okt 2026.
 * Pegawai "Dodi" ditempatkan di MA Unit 1 (cakupan Kepala Unit 1), "Mira" di MDA Unit 1 (Koordinator MDA
 * hanya boleh melihat pegawai, bukan data pribadi).
 */

function dokEmployee(mixed $test, string $name, string $orgCode): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function dokType(mixed $test, string $code): string
{
    return (string) DocumentType::query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');
}

/**
 * File unggahan "asli" (bukan UploadedFile::fake), agar jenis file dibaca dari isinya.
 */
function dokFile(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'dok');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function dokPdf(string $name = 'SK Dodi.pdf', int $padding = 200): UploadedFile
{
    return dokFile($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n".str_repeat('%', $padding)."\n%%EOF\n");
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->dodi = dokEmployee($this, 'Dodi', 'U1-MA');
    $this->mira = dokEmployee($this, 'Mira', 'U1-MDA');
});

test('isi awal jenis dokumen: Kontrak Kerja punya masa berlaku; jenis dokumen bisa dikelola di Katalog SDM', function () {
    expect(DocumentType::query()->count())->toBe(7)
        ->and(DocumentType::query()->where('has_expiry', true)->pluck('code')->all())->toBe(['KONTRAK']);

    $this->actingAs($this->admin)
        ->get(route('hr.catalog.index', ['jenis' => 'jenis-dokumen']))
        ->assertInertia(fn (Assert $page) => $page->where('hasExpiryFlag', true)->has('items', 7));

    $this->post(route('hr.catalog.store', ['kind' => 'jenis-dokumen']), ['code' => 'str', 'name' => 'Surat Tanda Registrasi', 'has_expiry' => '1'])
        ->assertSessionHasNoErrors();
    $str = DocumentType::query()->where('code', 'STR')->sole();
    expect($str->has_expiry)->toBeTrue();

    $this->patch(route('hr.catalog.update', ['kind' => 'jenis-dokumen', 'item' => $str->id]), ['name' => 'Surat Tanda Registrasi', 'has_expiry' => '0'])
        ->assertSessionHasNoErrors();
    expect($str->refresh()->has_expiry)->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::CATALOG_UPDATED)->count())->toBe(1);

    // Katalog lain tidak punya pilihan masa berlaku.
    $this->get(route('hr.catalog.index', ['jenis' => 'jabatan']))
        ->assertInertia(fn (Assert $page) => $page->where('hasExpiryFlag', false)->where('items.0.has_expiry', null));
});

test('Admin mengunggah dokumen: file di disk privat, checksum, audit tanpa isi; tampil di detail pegawai dengan status masa berlaku', function () {
    $this->actingAs($this->admin)
        ->post(route('hr.employees.documents.store', ['employee' => $this->dodi]), [
            'document_type_id' => dokType($this, 'SK-PENGANGKATAN'), 'number' => '012/SK/YYS/2020', 'title' => 'SK Pengangkatan GTY',
            'issued_on' => '2020-07-01', 'valid_from' => '2020-07-01', 'note' => 'Asli di lemari arsip',
            'file' => dokPdf('../../SK Dodi.pdf'),
        ])
        ->assertSessionHasNoErrors();

    $this->post(route('hr.employees.documents.store', ['employee' => $this->dodi]), [
        'document_type_id' => dokType($this, 'KONTRAK'), 'title' => 'Kontrak 2026', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-01',
        'file' => dokFile('kontrak.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')),
    ])->assertSessionHasNoErrors();

    $sk = EmployeeDocument::query()->where('title', 'SK Pengangkatan GTY')->sole();

    expect($sk->storage_path)->toBe("tenants/{$this->tenant->id}/hr/documents/{$sk->id}.pdf")
        ->and($sk->original_name)->toBe('SK Dodi.pdf')
        ->and($sk->mime_type)->toBe('application/pdf')
        ->and($sk->sha256)->toBe(hash('sha256', Storage::disk('local')->get($sk->storage_path)))
        ->and(EmployeeDocument::query()->where('title', 'Kontrak 2026')->sole()->mime_type)->toBe('image/png');

    Storage::disk('local')->assertExists($sk->storage_path);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::DOCUMENT_ADDED)->pluck('changes')->implode(' ');
    expect($audit)->toContain('SK-PENGANGKATAN')->not->toContain('012/SK')->not->toContain('Pengangkatan GTY')->not->toContain('lemari');

    // Kepala Unit 1 boleh melihat data pribadi pegawai MA Unit 1 → daftar dokumen tampil (tanpa form kelola).
    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', ['employee' => $this->dodi]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manage', null)
            ->has('documents', 2)
            ->where('documents.0.title', 'Kontrak 2026')
            ->where('documents.0.expiry', 'SEGERA')
            ->where('documents.1.title', 'SK Pengangkatan GTY')
            ->where('documents.1.expiry', null));
});

test('aturan isian & file: jenis berbatas wajib tanggal berlaku sampai, urutan tanggal, file palsu & terlalu besar ditolak', function () {
    $this->actingAs($this->admin);
    $url = route('hr.employees.documents.store', ['employee' => $this->dodi]);
    $base = ['document_type_id' => dokType($this, 'KONTRAK'), 'title' => 'Kontrak'];

    $this->post($url, [...$base, 'file' => dokPdf()])->assertSessionHasErrors(['valid_until' => 'Kontrak Kerja punya masa berlaku: isi tanggal berlaku sampai.']);
    $this->post($url, [...$base, 'valid_from' => '2026-05-01', 'valid_until' => '2026-04-30', 'file' => dokPdf()])->assertSessionHasErrors('valid_until');
    $this->post($url, [...$base, 'valid_until' => '2027-01-01', 'issued_on' => '2026-10-25', 'file' => dokPdf()])->assertSessionHasErrors(['issued_on' => 'Tanggal terbit tidak boleh di masa depan.']);
    // Teks berekstensi .pdf: jenis file dibaca dari isinya.
    $this->post($url, [...$base, 'valid_until' => '2027-01-01', 'file' => dokFile('palsu.pdf', 'bukan pdf <script>alert(1)</script>')])->assertSessionHasErrors(['file' => 'File harus berupa PDF, JPG, atau PNG.']);
    $this->post($url, [...$base, 'valid_until' => '2027-01-01', 'file' => dokPdf('besar.pdf', 5 * 1024 * 1024 + 10)])->assertSessionHasErrors(['file' => 'Ukuran file maksimal 5 MB.']);
    $this->post($url, [...$base, 'valid_until' => '2027-01-01'])->assertSessionHasErrors(['file' => 'Pilih file dokumen.']);

    expect(EmployeeDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('ubah isian (audit hanya nama field), batalkan dengan alasan; dokumen batal tidak bisa diubah tetapi tetap bisa diunduh', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.documents.store', ['employee' => $this->dodi]), [
        'document_type_id' => dokType($this, 'IJAZAH'), 'title' => 'Ijazah S1', 'file' => dokPdf(),
    ])->assertSessionHasNoErrors();
    $document = EmployeeDocument::query()->sole();
    $params = ['employee' => $this->dodi, 'document' => $document->id];

    $this->patch(route('hr.employees.documents.update', $params), [
        'document_type_id' => dokType($this, 'IJAZAH'), 'title' => 'Ijazah S1 Pendidikan Agama Islam', 'number' => 'UIN-123',
    ])->assertSessionHasNoErrors();

    expect($document->refresh()->title)->toBe('Ijazah S1 Pendidikan Agama Islam')
        ->and($document->number)->toBe('UIN-123');

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::DOCUMENT_UPDATED)->sole();
    expect(json_decode((string) $audit->changes, true)['field'])->toEqualCanonicalizing(['title', 'number'])
        ->and((string) $audit->changes)->not->toContain('UIN-123');

    $this->post(route('hr.employees.documents.void', $params), ['void_reason' => ''])->assertSessionHasErrors('void_reason');
    $this->post(route('hr.employees.documents.void', $params), ['void_reason' => 'Salah unggah file'])->assertSessionHasNoErrors();

    expect($document->refresh()->isVoided())->toBeTrue()
        ->and($document->void_reason)->toBe('Salah unggah file');

    $this->patch(route('hr.employees.documents.update', $params), ['document_type_id' => dokType($this, 'IJAZAH'), 'title' => 'Lain'])
        ->assertSessionHasErrors(['form' => 'Dokumen yang sudah dibatalkan tidak bisa diubah.']);
    $this->post(route('hr.employees.documents.void', $params), ['void_reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Dokumen ini sudah dibatalkan.']);

    $this->get(route('hr.employees.documents.download', $params))
        ->assertOk()
        ->assertDownload('SK Dodi.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(DB::table('audit_logs')->where('action', HrAuditAction::DOCUMENT_DOWNLOADED)->count())->toBe(1);

    $this->get(route('hr.employees.show', ['employee' => $this->dodi]))
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.voided', true)->where('documents.0.void_reason', 'Salah unggah file'));
});

test('hak akses: kelola hanya Admin; unduh hanya yang boleh melihat data pribadi; di luar cakupan 404', function () {
    $this->actingAs($this->admin)->post(route('hr.employees.documents.store', ['employee' => $this->mira]), [
        'document_type_id' => dokType($this, 'SK-JABATAN'), 'title' => 'SK Guru MDA', 'file' => dokPdf(),
    ])->assertSessionHasNoErrors();
    $miraDocument = ['employee' => $this->mira, 'document' => EmployeeDocument::query()->sole()->id];
    $this->flushSession();

    // Kepala Unit 1: lihat data pribadi MDA Unit 1 → boleh unduh; tidak boleh mengunggah.
    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.employees.documents.download', $miraDocument))->assertOk();
    $this->actingAs($kepala)->post(route('hr.employees.documents.store', ['employee' => $this->mira]), [
        'document_type_id' => dokType($this, 'SK-JABATAN'), 'title' => 'X', 'file' => dokPdf(),
    ])->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.employees.documents.void', $miraDocument), ['void_reason' => 'x'])->assertForbidden();
    $this->flushSession();

    // Koordinator MDA: melihat pegawai MDA tanpa data pribadi → 403, dan tidak mendapat daftar dokumen.
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    $this->actingAs($koordinator)->get(route('hr.employees.documents.download', $miraDocument))->assertForbidden();
    $this->actingAs($koordinator)->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->where('documents', null));
    $this->flushSession();

    // Kepala SMK Unit 3: pegawai Unit 1 di luar cakupan → 404.
    $this->actingAs(DevAccounts::user('kepala.smk'))->get(route('hr.employees.documents.download', $miraDocument))->assertNotFound();
    $this->flushSession();

    // Dokumen pegawai lain lewat URL pegawai ini → 404.
    $this->actingAs($this->admin)->get(route('hr.employees.documents.download', ['employee' => $this->dodi, 'document' => $miraDocument['document']]))->assertNotFound();
});
