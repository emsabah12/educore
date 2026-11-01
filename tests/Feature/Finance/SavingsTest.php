<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Tabungan siswa TB-1 (PRD-005 §3–§5). "Hari ini" Minggu 1 Nov 2026, SMP Unit 3, siswa Ahmad Fauzi.
 * Setoran & penarikan dicatat di Kasir, slip TAB-U3-SMP/2026/000001 dst., tercermin di rekening kas Titipan tabungan.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->actingAs($this->admin);

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();

    $this->enrollment = DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->sole();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnsEntry(mixed $test, string $amount, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $test->smp->id, 'enrollment_id' => $test->enrollment->id, 'amount' => $amount, 'entry_date' => '2026-11-01', 'method' => 'TUNAI',
    ], $overrides);
}

function fnsSlip(string $number): object
{
    return DB::table('fn_savings_entries')->where('slip_number', $number)->sole();
}

function fnsBalance(): int
{
    return (int) DB::table('fn_savings_accounts')->value('balance');
}

test('setor & tarik: saldo, slip bernomor, tercermin di rekening Titipan tabungan, tampil di tab Tabungan kasir', function () {
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '50.000', ['note' => 'Tabungan pekan 1']))
        ->assertRedirect(route('finance.savings.slip', ['entry' => fnsSlip('TAB-U3-SMP/2026/000001')->id]))
        ->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '25000', ['entry_date' => '2026-10-20', 'method' => 'TRANSFER']))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.withdraw'), fnsEntry($this, '30000', ['taken_by' => '  Ibu   Siti (ibu) ']))->assertRedirect()->assertSessionHasNoErrors();

    expect(fnsBalance())->toBe(45000)
        ->and(DB::table('fn_savings_accounts')->count())->toBe(1)
        ->and(fnsSlip('TAB-U3-SMP/2026/000003')->taken_by)->toBe('Ibu Siti (ibu)')
        ->and((int) fnsSlip('TAB-U3-SMP/2026/000003')->balance_after)->toBe(45000);

    // Buku kas: rekening Titipan tabungan (otomatis), kategori sistem setoran/penarikan.
    $custody = DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->where('kind', 'TITIPAN')->sole();
    expect($custody->name)->toBe('Titipan tabungan')
        ->and(DB::table('fn_cash_entries')->where('account_id', $custody->id)->where('source', 'TABUNGAN')->count())->toBe(3)
        ->and(DB::table('fn_cash_entries')->where('source_id', fnsSlip('TAB-U3-SMP/2026/000003')->id)->value('description'))
        ->toBe('Penarikan tabungan — Ahmad Fauzi (TAB-U3-SMP/2026/000003)')
        ->and(DB::table('fn_cash_entries as e')->join('fn_cash_categories as c', 'c.id', '=', 'e.category_id')->where('e.source_id', fnsSlip('TAB-U3-SMP/2026/000001')->id)->value('c.code'))
        ->toBe('SETORAN-TABUNGAN');

    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => $this->enrollment->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cashier')
            ->where('canSave', true)
            ->where('savings.balance', 45000)
            ->has('savings.entries', 3)
            ->where('savings.entries.0.slip_number', 'TAB-U3-SMP/2026/000003')
            ->where('savings.entries.0.kind_label', 'Penarikan')
            ->where('savings.entries.1.kind', 'SETOR')
            ->where('savings.entries.2.entry_date', '2026-10-20')
            ->where('savings.entries.2.method', 'Transfer bank'));

    $this->get(route('finance.savings.slip', ['entry' => fnsSlip('TAB-U3-SMP/2026/000001')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/savings-slip')
            ->where('slip.kind_label', 'Setoran')
            ->where('slip.amount', 50000)
            ->where('slip.amount_words', 'lima puluh ribu rupiah')
            ->where('slip.student.name', 'Ahmad Fauzi')
            ->where('slip.note', 'Tabungan pekan 1')
            ->where('slip.recorded_by', 'Admin Yayasan')
            ->where('amountLabel', 'Rp 50.000')
            ->where('balanceLabel', 'Rp 50.000')
            ->where('backUrl', '/keuangan/kasir?lembaga='.$this->smp->id.'&siswa='.$this->enrollment->id.'&tab=tabungan'));

    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::SAVINGS_DEPOSITED)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::SAVINGS_WITHDRAWN)->count())->toBe(1);
});

test('aturan isian: jumlah, tanggal, nama pengambil, saldo tidak cukup, siswa sudah keluar', function () {
    $deposit = route('finance.savings.deposit');
    $withdraw = route('finance.savings.withdraw');

    $this->post($deposit, fnsEntry($this, '0'))->assertSessionHasErrors('amount');
    $this->post($deposit, fnsEntry($this, '100000001'))->assertSessionHasErrors('amount');
    $this->post($deposit, fnsEntry($this, '1000', ['entry_date' => '2026-11-02']))->assertSessionHasErrors('entry_date');
    $this->post($deposit, fnsEntry($this, '1000', ['entry_date' => '2025-10-31']))->assertSessionHasErrors('entry_date');
    $this->post($deposit, fnsEntry($this, '1000', ['method' => 'TABUNGAN']))->assertSessionHasErrors('method');
    $this->post($deposit, fnsEntry($this, '1000', ['taken_by' => 'X']))->assertSessionHasErrors('taken_by');

    // Belum punya tabungan / saldo kurang / nama pengambil wajib.
    $this->post($withdraw, fnsEntry($this, '1000', ['taken_by' => 'Ahmad']))
        ->assertSessionHasErrors(['amount' => 'Siswa ini belum punya tabungan di lembaga ini.']);
    $this->post($deposit, fnsEntry($this, '20000'))->assertRedirect()->assertSessionHasNoErrors();
    $this->post($withdraw, fnsEntry($this, '20001', ['taken_by' => 'Ahmad']))
        ->assertSessionHasErrors(['amount' => 'Saldo tabungan tidak cukup. Saldo sekarang Rp 20.000.']);
    $this->post($withdraw, fnsEntry($this, '1000'))->assertSessionHasErrors('taken_by');

    // Siswa keluar: tidak bisa menabung, saldo masih bisa ditarik.
    $this->post(route('academic.enrollments.close', ['student' => $this->enrollment->student_id, 'enrollment' => $this->enrollment->id]), [
        'end_date' => '2026-10-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();

    $this->post($deposit, fnsEntry($this, '1000'))->assertSessionHasErrors('enrollment_id');
    $this->post($withdraw, fnsEntry($this, '20.000', ['taken_by' => 'Bapak Ahmad']))->assertRedirect()->assertSessionHasNoErrors();

    expect(fnsBalance())->toBe(0)
        ->and(DB::table('fn_savings_entries')->count())->toBe(2);
});

test('batal: saldo dihitung ulang, baris kas ikut batal; batal setoran ditolak bila saldonya sudah ditarik', function () {
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '50000'))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.withdraw'), fnsEntry($this, '40000', ['taken_by' => 'Ahmad']))->assertRedirect()->assertSessionHasNoErrors();
    $deposit = fnsSlip('TAB-U3-SMP/2026/000001');
    $withdrawal = fnsSlip('TAB-U3-SMP/2026/000002');

    $this->post(route('finance.savings.void', ['entry' => $deposit->id]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('finance.savings.void', ['entry' => $deposit->id]), ['reason' => 'Salah siswa'])->assertSessionHasErrors('form');
    expect(fnsBalance())->toBe(10000);

    $this->post(route('finance.savings.void', ['entry' => $withdrawal->id]), ['reason' => 'Batal ambil'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.void', ['entry' => $deposit->id]), ['reason' => 'Salah siswa'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.void', ['entry' => $deposit->id]), ['reason' => 'Lagi'])
        ->assertSessionHasErrors(['form' => 'Transaksi ini sudah dibatalkan.']);

    expect(fnsBalance())->toBe(0)
        ->and(DB::table('fn_savings_entries')->where('status', 'BATAL')->count())->toBe(2)
        ->and(DB::table('fn_cash_entries')->where('source', 'TABUNGAN')->where('status', 'SAH')->count())->toBe(0)
        ->and(DB::table('fn_cash_entries')->where('source_id', $deposit->id)->value('void_reason'))->toBe('Transaksi tabungan dibatalkan: Salah siswa')
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::SAVINGS_ENTRY_VOIDED)->count())->toBe(2);

    // Nomor slip tidak dipakai ulang; slip batal tetap bisa dibuka.
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '5000'))->assertRedirect()->assertSessionHasNoErrors();
    expect(fnsSlip('TAB-U3-SMP/2026/000003')->amount)->toEqual(5000);
    $this->get(route('finance.savings.slip', ['entry' => $deposit->id]))
        ->assertInertia(fn (Assert $page) => $page->where('slip.status', 'BATAL')->where('slip.void_reason', 'Salah siswa'));
});

test('rekening Titipan tabungan terpisah: tidak dihitung di buku kas "semua rekening" & laporan, tidak bisa dipakai manual', function () {
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '75000'))->assertRedirect()->assertSessionHasNoErrors();
    $custody = (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->where('kind', 'TITIPAN')->value('id');

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 2)
            ->where('accounts.1.kind', 'TITIPAN')
            ->where('accounts.1.balance', 75000)
            // Hanya baris saldo awal Kas tunai; setoran tabungan tidak ikut.
            ->has('ledger.rows', 1)
            ->where('ledger.rows.0.type', 'opening')
            ->where('ledger.total_in', 0)
            ->where('transferTargets', fn ($targets): bool => ! collect($targets)->contains('value', $custody)));

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id, 'rekening' => $custody]))
        ->assertInertia(fn (Assert $page) => $page->has('ledger.rows', 1)->where('ledger.rows.0.category', 'Setoran tabungan')->where('ledger.closing', 75000));

    $this->get(route('finance.cash-report.index', ['kas' => $this->smp->id, 'periode' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('report.income_total', 0)->where('report.closing', 0));

    $cash = (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->where('kind', 'TUNAI')->value('id');
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $custody, 'entry_date' => '2026-11-01',
        'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'DONASI')->value('id'), 'amount' => '1000', 'description' => 'Donasi',
    ])->assertSessionHasErrors('account_id');
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $custody, 'to_account_id' => $cash, 'entry_date' => '2026-11-01', 'amount' => '1000', 'description' => 'Pindah',
    ])->assertSessionHasErrors('account_id');
    $this->patch(route('finance.cash-accounts.update', ['account' => $custody]), ['opening_balance' => '0', 'opening_date' => '2026-11-01'])
        ->assertSessionHasErrors('form');
});

test('hak akses: kepala lembaga hanya melihat; bendahara lembaga lain tidak melihat (404)', function () {
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '10000'))->assertRedirect()->assertSessionHasNoErrors();
    $entry = fnsSlip('TAB-U3-SMP/2026/000001');

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $otherBendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($otherBendahara, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($kepala)->get(route('dashboard'))->assertOk();
    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => $this->enrollment->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canSave', false)->where('savings.balance', 10000));
    $this->get(route('finance.savings.slip', ['entry' => $entry->id]))->assertOk();
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '1000'))->assertForbidden();
    $this->post(route('finance.savings.void', ['entry' => $entry->id]), ['reason' => 'x'])->assertForbidden();

    $this->flushSession();
    $this->actingAs($otherBendahara)->get(route('dashboard'))->assertOk();
    $this->get(route('finance.savings.slip', ['entry' => $entry->id]))->assertNotFound();
    $this->post(route('finance.savings.deposit'), fnsEntry($this, '1000'))->assertNotFound();
    $this->post(route('finance.savings.void', ['entry' => $entry->id]), ['reason' => 'x'])->assertNotFound();

    expect(fnsBalance())->toBe(10000);
});
