<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\OffboardingItem;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Offboarding pegawai keluar (PRD-001 §18.2, HR-7b; OD-HR-15). "Sekarang" Rabu 11 Nov 2026.
 * Guru (akun `guru`, aktif) di MA Unit 1, wali kelas X-A; Mira (tanpa akun) di MDA Unit 1.
 */

function ofbEmployee(mixed $test, string $name, string $orgCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function ofbEnd(mixed $test, string $employeeId): void
{
    $test->post(route('hr.employees.end', ['employee' => $employeeId]), [
        'end_reason' => 'MENGUNDURKAN_DIRI', 'end_date' => '2026-11-10',
    ])->assertSessionHasNoErrors();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->guruMembership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id;
    $this->guruId = DB::table('hr_employees')->where('membership_id', $this->guruMembership)->value('id')
        ?? ofbEmployee($this, 'Guru', 'U1-MA', $this->guruMembership);
    $this->mira = ofbEmployee($this, 'Mira', 'U1-MDA');
});

test('katalog bawaan berisi 6 item offboarding dan bisa dikelola di Katalog SDM', function () {
    expect(OffboardingItem::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->pluck('code')->sort()->values()->all())
        ->toBe(['AKUN-LOGIN', 'GRUP', 'HAK-AKHIR', 'INVENTARIS', 'PERANGKAT', 'SERAH-TERIMA']);

    $this->actingAs($this->admin)
        ->get(route('hr.catalog.index', ['jenis' => 'item-offboarding']))
        ->assertInertia(fn (Assert $page) => $page->has('items', 6));

    $this->post(route('hr.catalog.store', ['kind' => 'item-offboarding']), ['code' => 'seragam', 'name' => 'Kembalikan seragam'])->assertSessionHasNoErrors();
    expect(OffboardingItem::query()->where('code', 'SERAGAM')->exists())->toBeTrue();
});

test('berhentikan → checklist dari item aktif; pemeriksaan akun aktif, wali kelas, cuti; ubah status item; selesai', function () {
    // Item nonaktif tidak ikut checklist.
    $grup = OffboardingItem::query()->withoutGlobalScope('tenant')->where('code', 'GRUP')->sole();
    $grup->is_active = false;
    $grup->save();

    // Guru masih wali kelas di tahun ajaran berjalan.
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    DB::table('ac_classes')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id,
        'academic_year_id' => $year->id, 'grade' => 10, 'name' => 'X-A', 'homeroom_employee_id' => $this->guruId, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin);
    ofbEnd($this, $this->guruId);

    expect(DB::table('hr_offboardings')->where('employee_id', $this->guruId)->count())->toBe(1)
        ->and(DB::table('hr_offboarding_tasks')->count())->toBe(5)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::OFFBOARDING_STARTED)->count())->toBe(1);

    $this->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('offboarding.checklists', 1)
            ->where('offboarding.checklists.0.end_date', '2026-11-10')
            ->where('offboarding.checklists.0.reason', 'Mengundurkan diri')
            ->where('offboarding.checklists.0.done', 0)
            ->where('offboarding.checklists.0.total', 5)
            ->where('offboarding.checks.account', 'ACTIVE')
            ->where('offboarding.checks.membership_id', $this->guruMembership)
            ->where('offboarding.checks.duties', ['Wali kelas X-A (MA Unit 1)'])
            ->where('offboarding.canStart', false)
            ->where('offboarding.canManageAccess', true));

    $this->get(route('hr.offboarding.index'))
        ->assertInertia(fn (Assert $page) => $page->component('hr/offboarding/index')->has('rows', 1)->where('rows.0.done', 0)->where('rows.0.completed', false));

    // Ubah item: selesai dengan catatan, tidak perlu; status salah ditolak.
    $tasks = DB::table('hr_offboarding_tasks')->orderBy('position')->pluck('id', 'code');
    $update = fn (string $code, array $data) => $this->post(route('hr.employees.offboarding.task', ['employee' => $this->guruId, 'task' => $tasks[$code]]), $data);

    $update('PERANGKAT', ['status' => 'SELESAI', 'note' => 'Laptop diterima TU'])->assertSessionHasNoErrors();
    $update('INVENTARIS', ['status' => 'TIDAK_PERLU'])->assertSessionHasNoErrors();
    $update('SERAH-TERIMA', ['status' => 'ENTAH'])->assertSessionHasErrors('status');
    $update('SERAH-TERIMA', ['status' => 'SELESAI'])->assertSessionHasNoErrors();
    $update('HAK-AKHIR', ['status' => 'SELESAI'])->assertSessionHasNoErrors();

    expect(DB::table('hr_offboarding_tasks')->where('id', $tasks['PERANGKAT'])->value('note'))->toBe('Laptop diterima TU')
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::OFFBOARDING_TASK_UPDATED)->count())->toBe(4);

    // Akun login masih aktif → belum selesai; dinonaktifkan lewat Kelola akses → item akun tercentang otomatis.
    $this->get(route('hr.offboarding.index'))->assertInertia(fn (Assert $page) => $page->where('rows.0.done', 4)->where('rows.0.completed', false));
    $this->post(route('access.accounts.deactivate', ['membership' => $this->guruMembership]))->assertSessionHasNoErrors();

    $this->get(route('hr.employees.show', ['employee' => $this->guruId]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('offboarding.checks.account', 'INACTIVE')
            ->where('offboarding.checklists.0.completed', true)
            ->where('offboarding.checklists.0.tasks.0.code', 'AKUN-LOGIN')
            ->where('offboarding.checklists.0.tasks.0.auto', true));
    $this->get(route('hr.offboarding.index'))->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    $this->get(route('hr.offboarding.index', ['status' => 'semua']))->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.completed', true));
});

test('pegawai yang berhenti sebelum HR-7b dibuatkan checklist lewat tombol; aktif kembali lalu berhenti lagi → checklist baru', function () {
    $this->actingAs($this->admin);
    DB::table('hr_offboarding_items')->update(['is_active' => false]);
    ofbEnd($this, $this->mira);
    expect(DB::table('hr_offboardings')->count())->toBe(0);

    $this->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->where('offboarding.canStart', true)->has('offboarding.checklists', 0)->where('offboarding.checks.account', 'NONE'));
    $this->post(route('hr.employees.offboarding.start', ['employee' => $this->mira]))
        ->assertSessionHasErrors(['form' => 'Belum ada item offboarding yang aktif. Tambahkan di Katalog SDM → Item offboarding.']);

    DB::table('hr_offboarding_items')->update(['is_active' => true]);
    $this->post(route('hr.employees.offboarding.start', ['employee' => $this->mira]))->assertSessionHasNoErrors();
    $this->post(route('hr.employees.offboarding.start', ['employee' => $this->mira]))->assertSessionHasErrors(['form' => 'Checklist offboarding pegawai ini sudah ada.']);

    // Tanpa akun login: item akun otomatis selesai.
    $this->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->where('offboarding.checklists.0.done', 1)->where('offboarding.canStart', false));

    // Pegawai aktif tidak bisa dibuatkan checklist.
    $this->post(route('hr.employees.offboarding.start', ['employee' => $this->guruId]))
        ->assertSessionHasErrors(['form' => 'Checklist offboarding hanya untuk pegawai yang sudah berhenti.']);
});

test('hak akses: hanya Admin SDM / Admin Yayasan; Kepala Lembaga tidak melihat tab Offboarding', function () {
    $this->actingAs($this->admin);
    ofbEnd($this, $this->mira);
    $task = (string) DB::table('hr_offboarding_tasks')->value('id');
    $this->flushSession();

    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.offboarding.index'))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.employees.offboarding.task', ['employee' => $this->mira, 'task' => $task]), ['status' => 'SELESAI'])->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.employees.show', ['employee' => $this->mira]))
        ->assertInertia(fn (Assert $page) => $page->where('offboarding', null));
});
