<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrPayComponentSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;
use Modules\HR\Domain\Payroll\PayPositionRate;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Komponen gaji "Menurut jabatan" & menu Gaji pegawai (PRD-001 §16.1, HR-5a-2).
 *
 * Tunjangan jabatan: Wakil Kepala (semua lembaga) Rp 1.000.000; Operator (semua) Rp 400.000;
 * Operator di MTs Unit 1 Rp 500.000.
 *   Ahmad: Wakil Kepala di MA Unit 1 (utama) + Operator di MTs Unit 1 → 1.500.000 (rangkap dijumlahkan).
 *   Budi : Operator di MA Unit 1 → 400.000 (tarif semua lembaga).
 *   Cici : Guru → tanpa tarif jabatan, belum diatur.
 */

function jbtId(mixed $test, CatalogKind $kind, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');
}

function jbtEmployee(mixed $test, string $name, string $orgCode, string $positionCode): string
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: jbtId($test, CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id,
        positionId: jbtId($test, CatalogKind::Position, $positionCode),
    ))->id;
}

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrPayComponentSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS')->id;

    $this->ahmad = jbtEmployee($this, 'Ahmad', 'U1-MA', 'WAKIL-KEPALA');
    DB::table('hr_placements')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'employee_id' => $this->ahmad, 'organization_id' => $this->mts,
        'position_id' => jbtId($this, CatalogKind::Position, 'OPERATOR'), 'is_primary' => false, 'start_date' => '2024-07-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->budi = jbtEmployee($this, 'Budi', 'U1-MA', 'OPERATOR');
    $this->cici = jbtEmployee($this, 'Cici', 'U1-MA', 'GURU');

    $this->component = app(ManagePayComponents::class)->create($this->tenant->id, 'TUNJ-STRUKTURAL', 'Tunjangan struktural', PayComponentKind::Earning, PayComponentBasis::PerPosition);
    $this->rateUrl = route('hr.pay-rates.set', ['component' => $this->component->id]);
});

test('tarif jabatan: tarif lembaga didahulukan, rangkap jabatan dijumlahkan, tampil di setup gaji pegawai', function () {
    $this->actingAs($this->admin);
    $this->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'WAKIL-KEPALA'), 'amount' => '1.000.000'])->assertSessionHasNoErrors();
    $this->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'OPERATOR'), 'amount' => '400.000'])->assertSessionHasNoErrors();
    $this->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'OPERATOR'), 'organization_id' => $this->mts, 'amount' => '450.000'])->assertSessionHasNoErrors();
    // Menyimpan ulang jabatan × lembaga yang sama = mengubah nominal.
    $this->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'OPERATOR'), 'organization_id' => $this->mts, 'amount' => 'Rp 500.000'])->assertSessionHasNoErrors();

    expect(PayPositionRate::query()->withoutGlobalScope('tenant')->count())->toBe(3);

    $this->get(route('hr.payroll-employees.show', ['employee' => $this->ahmad]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/payroll/employee')
            ->has('pay.placements', 2)
            ->has('pay.positions', 2)
            ->where('pay.positions.0.position', 'Wakil Kepala')
            ->where('pay.positions.0.amount', 1000000)
            ->where('pay.positions.0.scope', 'SEMUA')
            ->where('pay.positions.1.position', 'Operator')
            ->where('pay.positions.1.amount', 500000)
            ->where('pay.positions.1.scope', 'LEMBAGA')
            ->where('pay.monthly_earning', 1500000)
            // Komponen Menurut jabatan tidak diisi per pegawai.
            ->where('pay.rows', fn ($rows) => collect($rows)->pluck('code')->doesntContain('TUNJ-STRUKTURAL')));

    $this->get(route('hr.payroll-employees.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/payroll/employees')
            ->has('rows', 3)
            ->where('rows.0.name', 'Ahmad')
            ->where('rows.0.position_total', 1500000)
            ->where('rows.1.name', 'Budi')
            ->where('rows.1.position_total', 400000)
            ->where('rows.2.name', 'Cici')
            ->where('rows.2.configured', false));

    // Saringan lembaga: MTs Unit 1 hanya Ahmad (penempatan rangkap).
    $this->get(route('hr.payroll-employees.index', ['lembaga' => $this->mts]))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'Ahmad'));

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::PAY_RATE_SET)->pluck('changes')->implode(' ');
    expect(DB::table('audit_logs')->where('action', HrAuditAction::PAY_RATE_SET)->count())->toBe(4)
        ->and($audit)->toContain('OPERATOR')->not->toContain('500000')->not->toContain('400000');
});

test('hapus tarif; aturan: hanya komponen Menurut jabatan yang aktif, nominal sah, jabatan wajib', function () {
    $this->actingAs($this->admin);
    $operator = jbtId($this, CatalogKind::Position, 'OPERATOR');

    $this->post($this->rateUrl, ['position_id' => $operator, 'amount' => '400.000,5'])->assertSessionHasErrors('amount');
    $this->post($this->rateUrl, ['amount' => '400000'])->assertSessionHasErrors(['position_id' => 'Pilih jabatan.']);
    $this->post(route('hr.pay-rates.set', ['component' => PayComponent::query()->where('code', 'GAPOK')->sole()->id]), ['position_id' => $operator, 'amount' => '1'])
        ->assertSessionHasErrors(['form' => 'Komponen gaji menurut jabatan tidak ditemukan.']);

    $this->post($this->rateUrl, ['position_id' => $operator, 'amount' => '400000'])->assertSessionHasNoErrors();
    $rate = PayPositionRate::query()->withoutGlobalScope('tenant')->sole();

    $this->delete(route('hr.pay-rates.remove', ['component' => $this->component->id, 'rate' => $rate->id]))->assertSessionHasNoErrors();

    expect(PayPositionRate::query()->withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::PAY_RATE_REMOVED)->count())->toBe(1);

    $this->get(route('hr.payroll-employees.show', ['employee' => $this->budi]))
        ->assertInertia(fn (Assert $page) => $page->where('pay.positions', [])->where('pay.monthly_earning', 0));

    // Komponen nonaktif: tarif tidak bisa ditambah dan tidak dihitung.
    app(ManagePayComponents::class)->setActive($this->tenant->id, $this->component->id, false);
    $this->post($this->rateUrl, ['position_id' => $operator, 'amount' => '400000'])->assertSessionHasErrors('form');
});

test('Pengaturan gaji menampilkan tarif jabatan; Kepala Lembaga tidak boleh mengubah tarif', function () {
    $this->actingAs($this->admin)->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'WAKIL-KEPALA'), 'amount' => '1000000']);

    $this->get(route('hr.payroll-settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('components', fn ($components) => collect($components)->firstWhere('code', 'TUNJ-STRUKTURAL')['rates'][0]['amount'] === 1000000)
            ->has('positions')
            ->has('organizations'));

    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post($this->rateUrl, ['position_id' => jbtId($this, CatalogKind::Position, 'OPERATOR'), 'amount' => '1'])
        ->assertForbidden();
});
