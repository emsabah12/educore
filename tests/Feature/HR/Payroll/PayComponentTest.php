<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrPayComponentSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Payroll\EmployeePayComponent;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Komponen gaji & nominal per pegawai (PRD-001 §16.1, HR-5a).
 */

function gjiEmployee(mixed $test, string $name): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

function gjiComponent(string $code): PayComponent
{
    return PayComponent::query()->withoutGlobalScope('tenant')->where('code', $code)->sole();
}

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrPayComponentSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->employee = gjiEmployee($this, 'Fajar');
});

test('isi awal komponen gaji aman diulang; komponen bisa ditambah, diubah nama, dinonaktifkan', function () {
    $this->seed(HrPayComponentSeeder::class);

    expect(PayComponent::query()->withoutGlobalScope('tenant')->count())->toBe(5)
        ->and(gjiComponent('TRANSPORT')->basis)->toBe(PayComponentBasis::PerPresentDay)
        ->and(gjiComponent('BPJS')->kind)->toBe(PayComponentKind::Deduction);

    $this->actingAs($this->admin)
        ->get(route('hr.payroll-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('hr/payroll/settings')->has('components', 5));

    $this->post(route('hr.pay-components.store'), ['code' => 'tunj-keluarga', 'name' => 'Tunjangan keluarga', 'kind' => 'PENDAPATAN', 'basis' => 'TETAP'])
        ->assertSessionHasNoErrors();
    $this->post(route('hr.pay-components.store'), ['code' => 'TUNJ-KELUARGA', 'name' => 'Lagi', 'kind' => 'PENDAPATAN', 'basis' => 'TETAP'])
        ->assertSessionHasErrors(['code' => 'Kode TUNJ-KELUARGA sudah dipakai komponen gaji lain.']);
    $this->post(route('hr.pay-components.store'), ['code' => 'X', 'name' => 'Salah', 'kind' => 'PENDAPATAN', 'basis' => 'BULANAN'])
        ->assertSessionHasErrors('basis');

    $family = gjiComponent('TUNJ-KELUARGA');
    $this->patch(route('hr.pay-components.update', ['component' => $family->id]), ['name' => 'Tunjangan keluarga & anak'])->assertSessionHasNoErrors();
    $this->post(route('hr.pay-components.deactivate', ['component' => $family->id]))->assertSessionHasNoErrors();
    $this->post(route('hr.pay-components.deactivate', ['component' => $family->id]))->assertSessionHasErrors('form');

    expect($family->refresh()->name)->toBe('Tunjangan keluarga & anak')
        ->and($family->is_active)->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::PAY_COMPONENT_DEACTIVATED)->count())->toBe(1);
});

test('nominal per pegawai: format rupiah, kosong = tidak berlaku, ringkasan, audit tanpa nominal', function () {
    $gapok = gjiComponent('GAPOK');
    $transport = gjiComponent('TRANSPORT');
    $bpjs = gjiComponent('BPJS');
    $url = route('hr.payroll-employees.update', ['employee' => $this->employee]);

    $this->actingAs($this->admin)
        ->patch($url, ['amounts' => [$gapok->id => 'Rp 2.500.000', $transport->id => '15000', $bpjs->id => '100.000']])
        ->assertSessionHasNoErrors();

    expect(EmployeePayComponent::query()->withoutGlobalScope('tenant')->where('employee_id', $this->employee)->pluck('amount', 'pay_component_id')->all())
        ->toEqual([$gapok->id => 2500000, $transport->id => 15000, $bpjs->id => 100000]);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_PAY_UPDATED)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['komponen' => ['BPJS', 'GAPOK', 'TRANSPORT']])
        ->and((string) $audit->changes)->not->toContain('2500000');

    $this->get(route('hr.payroll-employees.show', ['employee' => $this->employee]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/payroll/employee')
            ->where('pay.monthly_earning', 2500000)
            ->where('pay.monthly_deduction', 100000)
            ->where('pay.per_present_day', 15000)
            ->where('pay.configured', true)
            ->has('pay.rows', 5));

    // Detail pegawai tidak lagi memuat gaji (menu terpisah, keputusan owner 2026-10-27).
    $this->get(route('hr.employees.show', ['employee' => $this->employee]))
        ->assertInertia(fn (Assert $page) => $page->missing('pay'));

    // Ubah satu, kosongkan satu; nominal salah ditolak tanpa mengubah apa pun.
    $this->patch($url, ['amounts' => [$gapok->id => '2.750.000', $transport->id => '', $bpjs->id => '100000']])->assertSessionHasNoErrors();
    $this->patch($url, ['amounts' => [$gapok->id => '2.500.000,50']])->assertSessionHasErrors("amounts.{$gapok->id}");
    $this->patch($url, ['amounts' => [$gapok->id => '2000000000']])->assertSessionHasErrors("amounts.{$gapok->id}");

    expect(EmployeePayComponent::query()->withoutGlobalScope('tenant')->where('employee_id', $this->employee)->pluck('amount', 'pay_component_id')->all())
        ->toEqual([$gapok->id => 2750000, $bpjs->id => 100000])
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::EMPLOYEE_PAY_UPDATED)->count())->toBe(2);

    // Komponen nonaktif: nominal lama tetap, tidak bisa diubah, tidak masuk ringkasan.
    $this->post(route('hr.pay-components.deactivate', ['component' => $bpjs->id]));
    $this->patch($url, ['amounts' => [$bpjs->id => '5']])->assertSessionHasNoErrors();

    $this->get(route('hr.payroll-employees.show', ['employee' => $this->employee]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pay.monthly_deduction', 0)
            ->where('pay.rows', fn ($rows) => collect($rows)->firstWhere('code', 'BPJS')['amount'] === 100000));
});

test('hanya Admin SDM / Admin Yayasan: Kepala Lembaga 403 di semua halaman gaji', function () {
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)->get(route('hr.payroll-settings.index'))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.payroll-employees.index'))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.payroll-employees.show', ['employee' => $this->employee]))->assertForbidden();
    $this->actingAs($kepala)->patch(route('hr.payroll-employees.update', ['employee' => $this->employee]), ['amounts' => [gjiComponent('GAPOK')->id => '1']])->assertForbidden();

    expect(EmployeePayComponent::query()->withoutGlobalScope('tenant')->count())->toBe(0);
});
