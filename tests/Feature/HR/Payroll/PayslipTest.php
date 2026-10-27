<?php

use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Application\Payroll\ManagePayroll;
use Modules\HR\Application\Payroll\ManagePositionRates;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrPayComponentSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Slip gaji & Gaji saya (PRD-001 §16.3, HR-5c-2, OD-HR-12). "Sekarang" 22 Okt 2026; Oktober dikunci.
 * Pegawai akun `guru` ("Guru MA & MDA"): Wakil Kepala di MA Unit 1 → tunjangan struktural 1.000.000,
 * gaji pokok 2.500.000. Budi: gaji pokok 2.000.000.
 */

function slpEmployee(mixed $test, string $name, string $position, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male, birthPlace: null, birthDate: null,
        phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($test->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, $position),
    ))->id;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrPayComponentSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');

    $this->guruId = slpEmployee($this, 'Guru', 'WAKIL-KEPALA', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->budi = slpEmployee($this, 'Budi', 'GURU');

    $structural = app(ManagePayComponents::class)->create($this->tenant->id, 'TUNJ-STRUKTURAL', 'Tunjangan struktural', PayComponentKind::Earning, PayComponentBasis::PerPosition);
    $wakil = (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('code', 'WAKIL-KEPALA')->value('id');
    app(ManagePositionRates::class)->set($this->tenant->id, $structural->id, $wakil, null, '1000000');
    $gapok = (string) PayComponent::query()->withoutGlobalScope('tenant')->where('code', 'GAPOK')->value('id');
    app(EmployeePay::class)->save($this->tenant->id, $this->guruId, [$gapok => '2500000'], null);
    app(EmployeePay::class)->save($this->tenant->id, $this->budi, [$gapok => '2000000'], null);
});

test('slip hanya dari bulan terkunci; Admin mencetak semua, per pegawai; jabatan + nominal tampil', function () {
    $this->actingAs($this->admin)->get(route('hr.payroll-recap.payslips', ['month' => '2026-10']))->assertNotFound();

    app(ManagePayroll::class)->lock($this->tenant->id, '2026-10', null);

    $this->get(route('hr.payroll-recap.payslips', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/payslips')
            ->where('label', 'Oktober 2026')
            ->has('slips', 2)
            ->where('slips.0.name', 'Budi')
            ->where('slips.1.name', 'Guru MA & MDA')
            ->where('slips.1.lines.0', ['group' => 'JABATAN', 'label' => 'Tunjangan struktural', 'detail' => 'Wakil Kepala — MA Unit 1', 'kind' => 'PENDAPATAN', 'amount' => 1000000])
            ->where('slips.1.net', 3500000));

    $this->get(route('hr.payroll-recap.payslips', ['month' => '2026-10', 'pegawai' => $this->budi]))
        ->assertInertia(fn (Assert $page) => $page->has('slips', 1)->where('slips.0.net', 2000000));

    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.payroll-recap.payslips', ['month' => '2026-10']))->assertForbidden();
});

test('Gaji saya: pegawai hanya melihat slip miliknya dari bulan terkunci', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)->get(route('hr.my-pay.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('hr/my-pay')->where('linked', true)->where('months', []));

    app(ManagePayroll::class)->lock($this->tenant->id, '2026-10', null);

    $this->actingAs($this->guru)->get(route('hr.my-pay.index'))
        ->assertInertia(fn (Assert $page) => $page->has('months', 1)->where('months.0.month', '2026-10')->where('months.0.net', 3500000));

    $this->actingAs($this->guru)->get(route('hr.my-pay.payslip', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/payslips')
            ->has('slips', 1)
            ->where('slips.0.employee_id', $this->guruId)
            ->where('backUrl', '/gaji-saya'));

    $this->actingAs($this->guru)->get(route('hr.my-pay.payslip', ['month' => '2026-09']))->assertNotFound();
});

test('akun tanpa data pegawai: Gaji saya kosong dan slip 404', function () {
    app(ManagePayroll::class)->lock($this->tenant->id, '2026-10', null);
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)->get(route('hr.my-pay.index'))->assertInertia(fn (Assert $page) => $page->where('linked', false));
    $this->actingAs($kepala)->get(route('hr.my-pay.payslip', ['month' => '2026-10']))->assertNotFound();
});
