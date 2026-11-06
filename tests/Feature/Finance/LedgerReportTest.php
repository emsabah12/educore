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
 * Pembukuan PB-2b (PRD-007 §7): neraca saldo, buku besar, cek kecocokan. "Hari ini" Kamis 5 Nov 2026.
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

    fngIncome($this, '2026-10-10', '200000');
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '40000', 'entry_date' => '2026-10-15', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    fngPay($this, '2026-10-20');
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-10-25', 'amount' => '100000', 'description' => 'Setoran SPP',
    ])->assertSessionHasNoErrors();
});

function fngIncome(mixed $test, string $date, string $amount): void
{
    $test->post(route('finance.cash-income.store'), [
        'kas' => $test->smp->id, 'account_id' => $test->smpCash, 'entry_date' => $date, 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => $amount, 'description' => "Dana BOS {$date}",
    ])->assertSessionHasNoErrors();
}

function fngPay(mixed $test, string $date): void
{
    $test->post(route('finance.payments.store'), [
        'organization_id' => $test->smp->id, 'enrollment_id' => $test->enrollment, 'amount' => '150000', 'paid_on' => $date, 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
}

function fngAccount(string $code): string
{
    return (string) DB::table('fn_ledger_accounts')->where('code', $code)->value('id');
}

/**
 * Aktifkan pembukuan + transaksi November.
 */
function fngNovember(mixed $test, string $start, int $fiscalMonth): void
{
    $test->post(route('finance.ledger-period.set'), ['start_date' => $start, 'fiscal_start_month' => (string) $fiscalMonth])->assertSessionHasNoErrors();
    $test->artisan('finance:ledger-process')->assertSuccessful();

    fngIncome($test, '2026-11-02', '300000');
    fngPay($test, '2026-11-03');
    $test->post(route('finance.journals.store'), [
        'date' => '2026-11-04',
        'description' => 'Penyusutan peralatan November',
        'lines' => [
            ['account_id' => fngAccount('5-1700'), 'organization_id' => $test->smp->id, 'debit' => '50000', 'credit' => ''],
            ['account_id' => fngAccount('1-2900'), 'organization_id' => $test->smp->id, 'debit' => '', 'credit' => '50000'],
        ],
    ])->assertSessionHasNoErrors();
}

/**
 * Baris neraca saldo yang tidak nol → kode => [saldo awal, debit, kredit, saldo akhir] (+ = debit).
 *
 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
 */
function fngTrial(mixed $page): array
{
    $rows = [];

    foreach ($page['report']['rows'] as $row) {
        if ($row['opening'] !== 0 || $row['debit'] !== 0 || $row['credit'] !== 0) {
            $rows[$row['code']] = [$row['opening'], $row['debit'], $row['credit'], $row['closing']];
        }
    }

    return $rows;
}

/**
 * @return array<string, list<list<mixed>>>
 */
function fngXlsx(mixed $response): array
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

test('neraca saldo gabungan & per lingkup: saldo awal, mutasi, saldo akhir; selalu seimbang; unduh .xlsx', function () {
    fngNovember($this, '2026-11-01', 1);

    $page = $this->get(route('finance.trial-balance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/trial-balance')
            ->where('scope', 'semua')
            ->where('period.from', '2026-11-01')
            ->where('period.to', '2026-11-05')
            ->where('period.fiscal_start', '2026-01-01')
            ->where('presets.0', ['label' => 'Bulan ini', 'from' => '2026-11-01', 'to' => '2026-11-30']))
        ->viewData('page')['props'];

    expect(fngTrial($page))->toBe([
        '1-1100' => [1350000, 450000, 0, 1800000],
        '1-1300' => [40000, 0, 0, 40000],
        '1-1400' => [150000, 150000, 150000, 150000],
        '1-2900' => [0, 0, 50000, -50000],
        '2-1100' => [-40000, 0, 0, -40000],
        '3-1000' => [-1500000, 0, 0, -1500000],
        '4-1100' => [0, 0, 150000, -150000],
        '4-2100' => [0, 0, 300000, -300000],
        '5-1700' => [0, 50000, 0, 50000],
    ])
        ->and($page['report']['totals'])->toBe([
            'opening_debit' => 1540000, 'opening_credit' => 1540000, 'debit' => 650000, 'credit' => 650000, 'closing_debit' => 2040000, 'closing_credit' => 2040000,
        ]);

    // Mulai 3 Nov: pendapatan 1–2 Nov jadi saldo awal (masih tahun buku yang sama).
    $page = $this->get(route('finance.trial-balance.index', ['dari' => '2026-11-03', 'sampai' => '2026-11-30']))->viewData('page')['props'];
    expect(fngTrial($page)['4-2100'])->toBe([-300000, 0, 0, -300000])
        ->and(fngTrial($page)['4-1100'])->toBe([-150000, 0, 0, -150000])
        ->and($page['report']['totals']['opening_debit'])->toBe($page['report']['totals']['opening_credit']);

    // Lingkup Yayasan (pusat): hanya kas Yayasan.
    $page = $this->get(route('finance.trial-balance.index', ['lingkup' => 'yayasan']))->viewData('page')['props'];
    expect(fngTrial($page))->toBe(['1-1100' => [100000, 0, 0, 100000], '3-1000' => [-100000, 0, 0, -100000]]);

    // Sebelum tanggal mulai dijepit; rentang lebih dari satu tahun buku dipotong.
    $this->get(route('finance.trial-balance.index', ['dari' => '2026-01-01', 'sampai' => '2027-03-31']))
        ->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-11-01')->where('period.to', '2026-12-31')->where('period.clamped', true));

    $response = $this->get(route('finance.trial-balance.export', ['lingkup' => 'semua']))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('neraca-saldo-semua-2026-11-01-2026-11-05.xlsx');
    $sheet = fngXlsx($response)['Neraca saldo'];
    expect($sheet[0][0])->toBe('Neraca saldo — Gabungan seluruh yayasan')
        ->and($sheet[3])->toBe(['1-1100', 'Kas tunai', 1350000, 0, 450000, 0, 1800000, 0])
        ->and(end($sheet))->toBe(['', 'Total', 1540000, 1540000, 650000, 650000, 2040000, 2040000]);
});

test('hasil tahun buku sebelumnya masuk aset neto tanpa jurnal tutup buku', function () {
    // Tahun buku November–Oktober, pembukuan mulai 1 Okt: Oktober = tahun buku sebelumnya.
    fngNovember($this, '2026-10-01', 11);

    $page = $this->get(route('finance.trial-balance.index', ['dari' => '2026-11-01', 'sampai' => '2026-11-05']))->viewData('page')['props'];
    $trial = fngTrial($page);

    // Pendapatan Oktober (BOS Rp 200.000 + SPP Okt Rp 150.000) tidak lagi di akun pendapatan, tetapi di Aset neto.
    expect($page['period']['fiscal_start'])->toBe('2026-11-01')
        ->and($trial['4-2100'][0])->toBe(0)
        ->and($trial['4-1100'][0])->toBe(0)
        ->and($trial['3-1000'][0])->toBe(-(1000000 + 150000 + 200000 + 150000))
        ->and($page['report']['totals']['opening_debit'])->toBe($page['report']['totals']['opening_credit'])
        ->and($page['report']['totals']['closing_debit'])->toBe($page['report']['totals']['closing_credit']);

    // Buku besar Aset neto: saldo awal sudah memuat surplus tahun lalu.
    $this->get(route('finance.general-ledger.index', ['akun' => fngAccount('3-1000'), 'dari' => '2026-11-01']))
        ->assertInertia(fn (Assert $page) => $page->where('ledger.opening', -1500000)->where('ledger.rows', []));
});

test('buku besar: saldo awal (termasuk jurnal saldo awal), saldo berjalan, lingkup lembaga, unduh .xlsx', function () {
    fngNovember($this, '2026-11-01', 1);

    $this->get(route('finance.general-ledger.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/general-ledger')
            ->where('account', fngAccount('1-1100'))
            ->where('ledger.account.code', '1-1100')
            ->where('ledger.opening', 1350000)
            ->where('ledger.debit', 450000)
            ->where('ledger.closing', 1800000)
            ->has('ledger.rows', 2)
            ->where('ledger.rows.0.description', 'Dana BOS 2026-11-02')
            ->where('ledger.rows.0.organization', 'SMP Unit 3')
            ->where('ledger.rows.0.balance', 1650000)
            ->where('ledger.rows.1.source_label', 'Transaksi kas')
            ->where('ledger.rows.1.balance', 1800000));

    $this->get(route('finance.general-ledger.index', ['akun' => fngAccount('1-1100'), 'lingkup' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('ledger.opening', 1250000)->where('ledger.closing', 1700000));
    $this->get(route('finance.general-ledger.index', ['akun' => fngAccount('1-1100'), 'dari' => '2026-11-03']))
        ->assertInertia(fn (Assert $page) => $page->where('ledger.opening', 1650000)->has('ledger.rows', 1));
    $this->get(route('finance.general-ledger.index', ['akun' => fngAccount('1-0000')]))
        ->assertInertia(fn (Assert $page) => $page->where('account', fngAccount('1-1100')));

    $response = $this->get(route('finance.general-ledger.export', ['akun' => fngAccount('1-1100'), 'lingkup' => 'semua']))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('buku-besar-1-1100-semua-2026-11-01-2026-11-05.xlsx');
    $sheet = fngXlsx($response)['Buku besar'];
    expect($sheet[0][0])->toBe('Buku besar 1-1100 Kas tunai — Gabungan seluruh yayasan')
        ->and($sheet[3])->toBe(['', '', 'Saldo awal', '', '', '', '', 1350000])
        ->and($sheet[4][2])->toBe('Dana BOS 2026-11-02')
        ->and($sheet[4][7])->toBe(1650000)
        ->and(end($sheet))->toBe(['', '', 'Saldo akhir', '', '', 450000, 0, 1800000]);

    $this->get(route('finance.general-ledger.export', ['akun' => 'bukan-akun']))->assertNotFound();
});

test('cek kecocokan: semua cocok; selisih piutang terdeteksi + kartu Beranda; hak akses', function () {
    fngNovember($this, '2026-11-01', 1);

    $this->get(route('finance.ledger-reconciliation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/ledger-reconciliation')
            ->where('mismatches', 0)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn (array $row): bool => $row['kind'] === 'KAS' && $row['label'] === 'Kas tunai' && $row['organization'] === 'SMP Unit 3' && $row['ledger'] === 1700000 && $row['book'] === 1700000)
                && collect($rows)->contains(fn (array $row): bool => $row['kind'] === 'PIUTANG' && $row['ledger'] === 150000 && $row['book'] === 150000)
                && collect($rows)->contains(fn (array $row): bool => $row['kind'] === 'TITIPAN' && $row['ledger'] === 40000 && $row['book'] === 40000)
                && collect($rows)->contains(fn (array $row): bool => $row['kind'] === 'RAK' && $row['difference'] === 0)));

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'finance.ledger_mismatch')));

    // Kuitansi November tanpa cerminan buku kas (mis. dihapus di luar aplikasi) → piutang pembukuan tidak berkurang.
    $payment = (string) DB::table('fn_payments')->where('paid_on', '2026-11-03')->value('id');
    DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->where('source_id', $payment)->delete();

    $this->get(route('finance.ledger-reconciliation.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mismatches', 1)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn (array $row): bool => $row['kind'] === 'PIUTANG' && $row['difference'] === 150000)));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card === [
        'key' => 'finance.ledger_mismatch', 'title' => 'Pembukuan tidak cocok', 'value' => 1,
        'description' => '1 saldo pembukuan berbeda dengan buku kas/tagihan/tabungan. Buka Cek kecocokan.', 'href' => '/keuangan/pembukuan/cek-kecocokan', 'tone' => 'warning',
    ])));

    // Kepala lembaga: hanya lembaganya, tanpa baris RAK & Kas Yayasan; laporan lain dibatasi lingkupnya.
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->actingAs($kepala)->get(route('finance.ledger-reconciliation.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->where('rows', fn ($rows) => collect($rows)->every(fn (array $row): bool => $row['organization'] === 'SMP Unit 3')));
    $this->actingAs($kepala)->get(route('finance.trial-balance.index', ['lingkup' => 'semua']))
        ->assertInertia(fn (Assert $page) => $page->where('scope', $this->smp->id)->where('scopes', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']]));
    $this->actingAs($kepala)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'finance.ledger_mismatch')));

    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->actingAs($bendahara)->get(route('finance.trial-balance.index'))->assertForbidden();
    $this->actingAs($bendahara)->get(route('finance.general-ledger.index'))->assertForbidden();
    $this->actingAs($bendahara)->get(route('finance.ledger-reconciliation.index'))->assertForbidden();
    $this->actingAs($bendahara)->get(route('finance.trial-balance.export'))->assertForbidden();
});
