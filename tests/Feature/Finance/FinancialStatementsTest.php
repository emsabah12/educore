<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pembukuan PB-3 (PRD-007 §7): laporan keuangan ISAK 35. "Hari ini" Kamis 5 Nov 2026, pembukuan mulai 1 Nov 2026.
 * SMP Unit 3: Kas tunai saldo awal Rp 1.000.000 per 1 Okt; SPP Rp 150.000/bulan Sep–Nov.
 *   10 Okt BOS Rp 200.000 · 15 Okt setor tabungan Rp 40.000 · 20 Okt kuitansi Rp 150.000 · 25 Okt mutasi ke Kas Yayasan Rp 100.000
 *   02 Nov BOS Rp 300.000 · 03 Nov kuitansi Rp 150.000 · 04 Nov jurnal manual penyusutan Rp 50.000
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs(DevAccounts::user('admin.yayasan'));

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $this->enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-09', 'to' => '2026-11',
    ])->assertSessionHasNoErrors();

    foreach (['yayasan', $this->smp->id] as $book) {
        $this->get(route('finance.cash.index', ['kas' => $book]))->assertOk();
    }

    $this->smpCash = (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->where('kind', 'TUNAI')->value('id');
    $this->yayasanCash = (string) DB::table('fn_cash_accounts')->whereNull('organization_id')->where('kind', 'TUNAI')->value('id');
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->smpCash]), ['opening_balance' => '1000000', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->yayasanCash]), ['opening_balance' => '0', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();

    fnhIncome($this, '2026-10-10', '200000');
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '40000', 'entry_date' => '2026-10-15', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    fnhPay($this, '2026-10-20');
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-10-25', 'amount' => '100000', 'description' => 'Setoran SPP',
    ])->assertSessionHasNoErrors();
});

function fnhIncome(mixed $test, string $date, string $amount): void
{
    $test->post(route('finance.cash-income.store'), [
        'kas' => $test->smp->id, 'account_id' => $test->smpCash, 'entry_date' => $date, 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => $amount, 'description' => "Dana BOS {$date}",
    ])->assertSessionHasNoErrors();
}

function fnhPay(mixed $test, string $date): void
{
    $test->post(route('finance.payments.store'), [
        'organization_id' => $test->smp->id, 'enrollment_id' => $test->enrollment, 'amount' => '150000', 'paid_on' => $date, 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
}

function fnhAccount(string $code): string
{
    return (string) DB::table('fn_ledger_accounts')->where('code', $code)->value('id');
}

/**
 * Aktifkan pembukuan + transaksi November.
 */
function fnhNovember(mixed $test, string $start, int $fiscalMonth): void
{
    $test->post(route('finance.ledger-period.set'), ['start_date' => $start, 'fiscal_start_month' => (string) $fiscalMonth])->assertSessionHasNoErrors();
    $test->artisan('finance:ledger-process')->assertSuccessful();

    fnhIncome($test, '2026-11-02', '300000');
    fnhPay($test, '2026-11-03');
    $test->post(route('finance.journals.store'), [
        'date' => '2026-11-04',
        'description' => 'Penyusutan peralatan November',
        'lines' => [
            ['account_id' => fnhAccount('5-1700'), 'organization_id' => $test->smp->id, 'debit' => '50000', 'credit' => ''],
            ['account_id' => fnhAccount('1-2900'), 'organization_id' => $test->smp->id, 'debit' => '', 'credit' => '50000'],
        ],
    ])->assertSessionHasNoErrors();
}

/**
 * @return array<string, list<list<mixed>>>
 */
function fnhXlsx(mixed $response): array
{
    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    return $sheets;
}

test('posisi keuangan, penghasilan komprehensif, perubahan aset neto, arus kas — gabungan; seimbang', function () {
    fnhNovember($this, '2026-11-01', 1);

    $props = $this->get(route('finance.financial-statements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/financial-statements')
            ->where('entity', 'Yayasan (nama resmi belum ditetapkan) — Gabungan seluruh yayasan')
            ->where('period.from', '2026-11-01')
            ->where('period.to', '2026-11-05'))
        ->viewData('page')['props'];

    $position = $props['position'];
    expect($position['previous_date'])->toBeNull()
        ->and($position['assets'])->toBe([
            ['title' => 'Aset lancar', 'lines' => [
                ['code' => '1-1100', 'name' => 'Kas tunai', 'current' => 1800000, 'previous' => null],
                ['code' => '1-1300', 'name' => 'Kas titipan tabungan siswa', 'current' => 40000, 'previous' => null],
                ['code' => '1-1400', 'name' => 'Piutang siswa', 'current' => 150000, 'previous' => null],
            ], 'current' => 1990000, 'previous' => null],
            ['title' => 'Aset tidak lancar', 'lines' => [
                ['code' => '1-2900', 'name' => 'Akumulasi penyusutan', 'current' => -50000, 'previous' => null],
            ], 'current' => -50000, 'previous' => null],
        ])
        ->and($position['total_assets'])->toBe(['current' => 1940000, 'previous' => null])
        ->and($position['total_liabilities'])->toBe(['current' => 40000, 'previous' => null])
        ->and($position['net_assets'])->toBe(['unrestricted' => ['current' => 1900000, 'previous' => null], 'restricted' => ['current' => 0, 'previous' => null]])
        ->and($position['total_liabilities']['current'] + $position['total_net_assets']['current'])->toBe($position['total_assets']['current']);

    $activities = $props['activities'];
    expect($activities['previous_from'])->toBeNull()
        ->and($activities['unrestricted']['revenue'][0]['lines'])->toBe([
            ['code' => '4-1100', 'name' => 'Pendapatan biaya pendidikan', 'current' => 150000, 'previous' => null],
            ['code' => '4-2100', 'name' => 'Pendapatan dana BOS', 'current' => 300000, 'previous' => null],
        ])
        ->and($activities['unrestricted']['revenue_total']['current'])->toBe(450000)
        ->and($activities['unrestricted']['expense_total']['current'])->toBe(50000)
        ->and($activities['unrestricted']['surplus']['current'])->toBe(400000)
        ->and($activities['restricted']['change']['current'])->toBe(0)
        ->and($activities['total']['current'])->toBe(400000);

    expect($props['changes'])->toBe([
        'unrestricted' => ['opening' => 1500000, 'change' => 400000, 'other' => 0, 'closing' => 1900000],
        'restricted' => ['opening' => 0, 'change' => 0, 'other' => 0, 'closing' => 0],
        'total' => ['opening' => 1500000, 'change' => 400000, 'other' => 0, 'closing' => 1900000],
    ]);

    $cash = $props['cashFlows'];
    expect($cash['activities'][0])->toBe([
        'activity' => 'OPERASI', 'label' => 'Arus kas dari aktivitas operasi',
        'receipts' => [['code' => '1-1400', 'name' => 'Piutang siswa', 'amount' => 150000], ['code' => '4-2100', 'name' => 'Pendapatan dana BOS', 'amount' => 300000]],
        'payments' => [], 'net' => 450000,
    ])
        ->and($cash['activities'][1]['net'] + $cash['activities'][2]['net'])->toBe(0)
        ->and([$cash['opening'], $cash['net_change'], $cash['closing']])->toBe([1350000, 450000, 1800000])
        ->and($cash['custody'])->toBe(['opening' => 40000, 'closing' => 40000]);

    // Unduh: empat lembar.
    $response = $this->get(route('finance.financial-statements.export'))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('laporan-keuangan-semua-2026-11-01-2026-11-05.xlsx');
    $sheets = fnhXlsx($response);
    expect(array_keys($sheets))->toBe(['Posisi keuangan', 'Penghasilan komprehensif', 'Perubahan aset neto', 'Arus kas'])
        ->and($sheets['Posisi keuangan'][1][0])->toBe('Laporan posisi keuangan per 5 November 2026')
        ->and(collect($sheets['Posisi keuangan'])->first(fn ($row) => $row[0] === 'Jumlah aset'))->toBe(['Jumlah aset', 1940000, ''])
        ->and(collect($sheets['Penghasilan komprehensif'])->first(fn ($row) => $row[0] === 'Surplus (defisit)'))->toBe(['Surplus (defisit)', 400000, ''])
        ->and(end($sheets['Perubahan aset neto']))->toBe(['Saldo akhir', 1900000, 0, 1900000])
        ->and(collect($sheets['Arus kas'])->first(fn ($row) => $row[0] === 'Kas & bank akhir periode'))->toBe(['Kas & bank akhir periode', 1800000]);
});

test('aset neto dengan pembatasan & pelepasannya; arus kas mutasi antar-kas per lembaga; kolom pembanding', function () {
    fnhNovember($this, '2026-11-01', 1);

    // Donasi terikat (akun ditandai "dengan pembatasan") Rp 1.000.000, lalu dibebaskan Rp 400.000 lewat jurnal manual.
    $this->patch(route('finance.ledger-accounts.update', ['account' => fnhAccount('4-3100')]), [
        'name' => 'Donasi / infak', 'parent_id' => fnhAccount('4-0000'), 'is_restricted' => '1', 'cash_flow_activity' => 'OPERASI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-11-02', 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'DONASI')->value('id'),
        'amount' => '1000000', 'description' => 'Donasi pembangunan musala',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.journals.store'), [
        'date' => '2026-11-04', 'description' => 'Pelepasan donasi musala (terpakai)',
        'lines' => [
            ['account_id' => fnhAccount('3-2000'), 'organization_id' => $this->smp->id, 'debit' => '400000', 'credit' => ''],
            ['account_id' => fnhAccount('3-1000'), 'organization_id' => $this->smp->id, 'debit' => '', 'credit' => '400000'],
        ],
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-11-04', 'amount' => '250000', 'description' => 'Setoran November',
    ])->assertSessionHasNoErrors();

    $props = $this->get(route('finance.financial-statements.index'))->viewData('page')['props'];
    $activities = $props['activities'];

    expect($activities['unrestricted']['released']['current'])->toBe(400000)
        ->and($activities['unrestricted']['surplus']['current'])->toBe(450000 + 400000 - 50000)
        ->and($activities['restricted']['revenue'][0]['lines'][0])->toBe(['code' => '4-3100', 'name' => 'Donasi / infak', 'current' => 1000000, 'previous' => null])
        ->and($activities['restricted']['released']['current'])->toBe(-400000)
        ->and($activities['restricted']['change']['current'])->toBe(600000)
        ->and($props['changes']['unrestricted'])->toBe(['opening' => 1500000, 'change' => 800000, 'other' => 0, 'closing' => 2300000])
        ->and($props['changes']['restricted'])->toBe(['opening' => 0, 'change' => 600000, 'other' => 0, 'closing' => 600000])
        ->and($props['position']['net_assets']['restricted']['current'])->toBe(600000)
        ->and($props['position']['total_liabilities']['current'] + $props['position']['total_net_assets']['current'])->toBe($props['position']['total_assets']['current']);

    // Gabungan: mutasi antar-kas tidak dihitung. SMP: setoran ke Yayasan = arus kas pendanaan (RAK).
    expect($props['cashFlows']['activities'][2]['net'])->toBe(0);
    $smp = $this->get(route('finance.financial-statements.index', ['lingkup' => $this->smp->id]))->viewData('page')['props']['cashFlows'];
    expect($smp['activities'][2]['payments'])->toBe([['code' => '1-9100', 'name' => 'Rekening antar-kantor (RAK)', 'amount' => 250000]])
        ->and($smp['opening'] + $smp['net_change'] + $smp['new_accounts'])->toBe($smp['closing']);

    // Pembanding: tahun buku Juli–Juni → per akhir tahun buku lalu (30 Jun 2026) sebelum tanggal mulai → tidak ada.
    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-01', 'fiscal_start_month' => '7'])->assertSessionHasNoErrors();
    $this->get(route('finance.financial-statements.index'))->assertInertia(fn (Assert $page) => $page->where('position.previous_date', null)->where('period.fiscal_start', '2026-07-01'));
});

test('pembanding tersedia bila periode lalu sudah dalam pembukuan', function () {
    // Pembukuan mulai 1 Okt 2026, tahun buku November–Oktober: akhir tahun buku lalu = 31 Okt 2026.
    fnhNovember($this, '2026-10-01', 11);

    $position = $this->get(route('finance.financial-statements.index', ['dari' => '2026-11-01', 'sampai' => '2026-11-05']))->viewData('page')['props']['position'];
    expect($position['previous_date'])->toBe('2026-10-31')
        ->and($position['total_assets']['previous'])->toBe($position['total_liabilities']['previous'] + $position['total_net_assets']['previous'])
        ->and($position['total_assets']['previous'])->not->toBe(0);
});

test('hak akses: kepala lembaga hanya lembaganya; bendahara ditolak', function () {
    fnhNovember($this, '2026-11-01', 1);

    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->actingAs($kepala)->get(route('finance.financial-statements.index', ['lingkup' => 'semua']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scope', $this->smp->id)->where('entity', 'Yayasan (nama resmi belum ditetapkan) — SMP Unit 3'));
    $this->actingAs($kepala)->get(route('finance.financial-statements.export'))->assertOk();

    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->actingAs($bendahara)->get(route('finance.financial-statements.index'))->assertForbidden();
    $this->actingAs($bendahara)->get(route('finance.financial-statements.export'))->assertForbidden();
});
