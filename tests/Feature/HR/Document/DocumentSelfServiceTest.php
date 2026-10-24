<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Document\DocumentData;
use Modules\HR\Application\Document\ManageEmployeeDocuments;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\DocumentType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Dokumen saya & Dokumen akan habis + kartu Beranda (PRD-001 §15.2, HR-2b). "Sekarang" 24 Okt 2026.
 *
 * Guru (akun `guru`, "Guru MA & MDA"): Kontrak habis 30 Sep (HABIS), SK Pengangkatan (tanpa masa berlaku),
 *   dokumen batal (Kontrak salah file, habis 1 Nov).
 * Hana: Kontrak habis 15 Des (SEGERA, 52 hari), Kontrak habis 31 Des (> 60 hari → tidak diingatkan).
 * Irma (sudah berhenti): Kontrak habis 1 Nov → tidak diingatkan.
 */

function dksEmployee(mixed $test, string $name, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function dksAdd(mixed $test, string $employeeId, string $typeCode, string $title, ?string $validUntil = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'dks');
    file_put_contents($path, "%PDF-1.4\n%%EOF\n");
    $typeId = (string) DocumentType::query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $typeCode)->value('id');

    return app(ManageEmployeeDocuments::class)->add(
        $test->tenant->id,
        $employeeId,
        new DocumentData($typeId, null, $title, null, null, $validUntil, null),
        new UploadedFile($path, "{$title}.pdf", null, null, true),
        null,
    )->id;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');

    $this->guruId = dksEmployee($this, 'Guru', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->hana = dksEmployee($this, 'Hana');
    $irma = dksEmployee($this, 'Irma');

    $this->expiredContract = dksAdd($this, $this->guruId, 'KONTRAK', 'Kontrak 2025', '2026-09-30');
    $this->appointment = dksAdd($this, $this->guruId, 'SK-PENGANGKATAN', 'SK Pengangkatan');
    $this->voided = dksAdd($this, $this->guruId, 'KONTRAK', 'Kontrak salah file', '2026-11-01');
    app(ManageEmployeeDocuments::class)->void($this->tenant->id, $this->guruId, $this->voided, 'Salah unggah', null);

    $this->hanaContract = dksAdd($this, $this->hana, 'KONTRAK', 'Kontrak Hana', '2026-12-15');
    dksAdd($this, $this->hana, 'KONTRAK', 'Kontrak Hana berikutnya', '2026-12-31');
    dksAdd($this, $irma, 'KONTRAK', 'Kontrak Irma', '2026-11-01');
    DB::table('hr_employees')->where('id', $irma)->update(['status' => 'BERHENTI']);
});

test('Dokumen saya: pegawai melihat & mengunduh dokumennya yang berlaku saja', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('hr.my-documents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/my-documents')
            ->where('linked', true)
            ->has('documents', 2)
            ->where('documents.0.title', 'SK Pengangkatan')
            ->where('documents.1.title', 'Kontrak 2025')
            ->where('documents.1.expiry', 'HABIS'));

    $this->actingAs($this->guru)->get(route('hr.my-documents.download', ['document' => $this->appointment]))
        ->assertOk()
        ->assertDownload('SK Pengangkatan.pdf');

    expect(DB::table('audit_logs')->where('action', HrAuditAction::DOCUMENT_DOWNLOADED)->count())->toBe(1);

    // Dokumen batal dan dokumen pegawai lain tidak bisa diunduh lewat Dokumen saya.
    $this->actingAs($this->guru)->get(route('hr.my-documents.download', ['document' => $this->voided]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('hr.my-documents.download', ['document' => $this->hanaContract]))->assertNotFound();
});

test('Dokumen saya untuk akun yang tidak tertaut ke data pegawai: pesan, tanpa daftar', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.my-documents.index'))
        ->assertInertia(fn (Assert $page) => $page->where('linked', false)->where('documents', [])->where('hr.isEmployee', false));

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.my-documents.download', ['document' => $this->appointment]))->assertNotFound();
});

test('Dokumen akan habis: habis & ≤ 60 hari milik pegawai aktif, urut tanggal; kartu Beranda untuk Admin', function () {
    $this->actingAs($this->admin)
        ->get(route('hr.documents.expiring'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/documents/expiring')
            ->has('rows', 2)
            ->where('rows.0.title', 'Kontrak 2025')
            ->where('rows.0.employee_name', 'Guru MA & MDA')
            ->where('rows.0.organization', 'MA Unit 1')
            ->where('rows.0.expiry', 'HABIS')
            ->where('rows.0.days_left', -24)
            ->where('rows.1.title', 'Kontrak Hana')
            ->where('rows.1.expiry', 'SEGERA')
            ->where('rows.1.days_left', 52));

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('cards', 1)
            ->where('cards.0.key', 'hr.expiring_documents')
            ->where('cards.0.value', 2)
            ->where('cards.0.tone', 'danger')
            ->where('cards.0.description', '1 sudah habis, 1 habis dalam 60 hari.'));
});

test('Dokumen akan habis hanya untuk Admin SDM / Admin Yayasan; Beranda pengguna lain tanpa kartu', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)->get(route('hr.documents.expiring'))->assertForbidden();
    $this->actingAs($kepala)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('cards', []));
});
