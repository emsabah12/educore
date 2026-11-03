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
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Bayar tagihan dari tabungan TB-3 (PRD-005 §4.3). "Hari ini" Selasa 3 Nov 2026, SMP Unit 3, Ahmad Fauzi:
 * SPP Rp 150.000 Juli–Oktober (Rp 600.000), tabungan Rp 200.000 (setor 2 Nov).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', BusinessClock::timezone()));

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
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-10',
    ])->assertSessionHasNoErrors();

    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '200000', 'entry_date' => '2026-11-02', 'method' => 'TUNAI',
    ])->assertRedirect()->assertSessionHasNoErrors();
});

function fnwPay(mixed $test, string $amount): mixed
{
    return $test->post(route('finance.payments.store'), [
        'organization_id' => $test->smp->id, 'enrollment_id' => $test->enrollment, 'amount' => $amount, 'paid_on' => '2026-11-03', 'method' => 'TABUNGAN',
    ]);
}

function fnwBalance(): int
{
    return (int) DB::table('fn_savings_accounts')->value('balance');
}

test('bayar dari tabungan: kuitansi bercara Tabungan, saldo berkurang, Titipan keluar, Kas tunai masuk, tampil di rekap penerimaan', function () {
    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => $this->enrollment]))
        ->assertInertia(fn (Assert $page) => $page->where('methods.2', ['value' => 'TABUNGAN', 'label' => 'Tabungan siswa'])->where('savings.balance', 200000));

    $payment = fn () => DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000001')->sole();
    fnwPay($this, '150.000')->assertSessionHasNoErrors();

    expect($payment()->method)->toBe('TABUNGAN')
        ->and(fnwBalance())->toBe(50000)
        ->and(DB::table('fn_invoices')->where('status', 'LUNAS')->count())->toBe(1);

    $entry = DB::table('fn_savings_entries')->where('kind', 'BAYAR_TAGIHAN')->sole();
    expect($entry->payment_id)->toBe($payment()->id)
        ->and($entry->slip_number)->toBe('TAB-U3-SMP/2026/000002')
        ->and($entry->note)->toBe('Kuitansi U3-SMP/2026/000001')
        ->and($entry->method)->toBeNull()
        ->and((int) $entry->balance_after)->toBe(50000);

    // Buku kas: KELUAR dari Titipan tabungan, MASUK "Pembayaran siswa" di Kas tunai (cerminan kuitansi).
    $custodyOut = DB::table('fn_cash_entries')->where('source', 'TABUNGAN')->where('source_id', $entry->id)->sole();
    $cashIn = DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->where('source_id', $payment()->id)->sole();
    expect($custodyOut->direction)->toBe('KELUAR')
        ->and($custodyOut->description)->toBe('Bayar tagihan dari tabungan — Ahmad Fauzi (TAB-U3-SMP/2026/000002, Kuitansi U3-SMP/2026/000001)')
        ->and(DB::table('fn_cash_accounts')->where('id', $custodyOut->account_id)->value('kind'))->toBe('TITIPAN')
        ->and(DB::table('fn_cash_accounts')->where('id', $cashIn->account_id)->value('kind'))->toBe('TUNAI')
        ->and((int) $cashIn->amount)->toBe(150000);

    $this->get(route('finance.receipts.show', ['payment' => $payment()->id]))
        ->assertInertia(fn (Assert $page) => $page->where('receipt.method', 'Tabungan siswa'));
    $this->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.totals.savings', 150000)->where('summary.totals.cash', 0)->where('summary.days.0.savings', 150000));
    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('monthly.payments', 150000)->where('monthly.payment_count', 1)->where('monthly.closing', 50000));

    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::SAVINGS_USED_FOR_PAYMENT)->count())->toBe(1);
});

test('saldo kurang: seluruh pembayaran ditolak; transaksi bayar tagihan tidak bisa dibatalkan dari tabungan', function () {
    fnwPay($this, '150000')->assertSessionHasNoErrors();

    fnwPay($this, '60000')->assertSessionHasErrors(['amount' => 'Saldo tabungan tidak cukup. Saldo sekarang Rp 50.000.']);
    expect(DB::table('fn_payments')->count())->toBe(1)
        ->and(DB::table('fn_invoices')->sum('paid_amount'))->toEqual(150000)
        ->and(fnwBalance())->toBe(50000);

    $entry = DB::table('fn_savings_entries')->where('kind', 'BAYAR_TAGIHAN')->sole();
    $this->post(route('finance.savings.void', ['entry' => $entry->id]), ['reason' => 'Salah'])
        ->assertSessionHasErrors(['form' => 'Pembayaran tagihan dari tabungan dibatalkan lewat kuitansinya.']);

    // Setor/tarik tidak boleh memakai cara "Tabungan".
    $this->post(route('finance.savings.withdraw'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '1000', 'entry_date' => '2026-11-03', 'method' => 'TABUNGAN', 'taken_by' => 'Ahmad',
    ])->assertSessionHasErrors('method');
});

test('kuitansi dari tabungan dibatalkan: tagihan terbuka lagi, saldo kembali, baris Titipan & Kas tunai ikut batal', function () {
    fnwPay($this, '150000')->assertSessionHasNoErrors();
    $payment = DB::table('fn_payments')->sole();

    $this->post(route('finance.payments.void', ['payment' => $payment->id]), ['reason' => 'Salah tagihan'])->assertRedirect()->assertSessionHasNoErrors();

    $entry = DB::table('fn_savings_entries')->where('kind', 'BAYAR_TAGIHAN')->sole();
    expect(fnwBalance())->toBe(200000)
        ->and($entry->status)->toBe('BATAL')
        ->and($entry->void_reason)->toBe('Kuitansi dibatalkan: Salah tagihan')
        ->and(DB::table('fn_cash_entries')->where('source_id', $entry->id)->value('status'))->toBe('BATAL')
        ->and(DB::table('fn_cash_entries')->where('source_id', $payment->id)->value('status'))->toBe('BATAL')
        ->and(DB::table('fn_invoices')->sum('paid_amount'))->toEqual(0);

    // Saldo yang kembali bisa dipakai lagi.
    fnwPay($this, '200000')->assertSessionHasNoErrors();
    expect(fnwBalance())->toBe(0);
});

test('siswa tanpa tabungan, dan kepala lembaga (tanpa izin kelola): ditolak', function () {
    $this->post(route('academic.students.store'), [
        'name' => 'Budi Santoso', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $other = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->where('id', '!=', $this->enrollment)->value('id');
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => (string) DB::table('fn_fee_types')->value('id'), 'from' => '2026-10', 'to' => '2026-10',
    ])->assertSessionHasNoErrors();

    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $other, 'amount' => '1000', 'paid_on' => '2026-11-03', 'method' => 'TABUNGAN',
    ])->assertSessionHasErrors(['amount' => 'Siswa ini belum punya tabungan di lembaga ini.']);
    expect(DB::table('fn_payments')->count())->toBe(0);

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->flushSession();
    $this->actingAs($kepala)->get(route('dashboard'))->assertOk();
    fnwPay($this, '1000')->assertForbidden();
});
