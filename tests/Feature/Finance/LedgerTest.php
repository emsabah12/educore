<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Discount\ManageDiscounts;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pembukuan PB-1 (PRD-007 §3–§5). "Hari ini" Kamis 5 Nov 2026, pembukuan mulai 1 Nov 2026.
 *   SMP Unit 3: Ahmad Fauzi, SPP Rp 150.000/bulan, potongan 10% (Rp 15.000) → tagihan Rp 135.000 Okt–Des.
 *   Kas tunai SMP saldo awal Rp 1.000.000 per 1 Okt (sebelum tanggal mulai → masuk jurnal saldo awal SMP, PB-2a).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $this->enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');

    $this->spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $this->spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    app(ManageDiscounts::class)->set($this->tenant->id, $this->smp->id, $this->enrollment, $this->spp->id, $this->year->id, ManageDiscounts::KIND_PERCENT, '10', 'Beasiswa prestasi', null);

    foreach (['yayasan', $this->smp->id] as $book) {
        $this->get(route('finance.cash.index', ['kas' => $book]))->assertOk();
    }

    $this->smpCash = fnlCashAccount($this->smp->id, 'TUNAI');
    $this->yayasanCash = fnlCashAccount(null, 'TUNAI');
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->smpCash]), ['opening_balance' => '1000000', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->yayasanCash]), ['opening_balance' => '0', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
});

function fnlCashAccount(?string $organizationId, string $kind): string
{
    return (string) DB::table('fn_cash_accounts')
        ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
        ->where('kind', $kind)->orderBy('created_at')->value('id');
}

function fnlAccount(string $code): string
{
    return (string) DB::table('fn_ledger_accounts')->where('code', $code)->value('id');
}

function fnlActivate(mixed $test, string $start = '2026-11-01'): void
{
    $test->post(route('finance.ledger-period.set'), ['start_date' => $start, 'fiscal_start_month' => '1'])->assertSessionHasNoErrors();
    fnlRebuild($test);
}

function fnlRebuild(mixed $test, bool $force = false): void
{
    $test->post(route('finance.ledger-rebuild'), $force ? ['mulai' => '1'] : [])->assertSessionHasNoErrors();

    for ($i = 0; $i < 20 && DB::table('fn_ledger_settings')->whereNotNull('rebuild_version')->exists(); $i++) {
        $test->post(route('finance.ledger-rebuild'))->assertSessionHasNoErrors();
    }

    expect(DB::table('fn_ledger_settings')->whereNotNull('rebuild_version')->exists())->toBeFalse();
}

function fnlGenerate(mixed $test, string $from, string $to): void
{
    $test->post(route('finance.invoices.generate'), [
        'organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'fee_type_id' => $test->spp->id, 'from' => $from, 'to' => $to,
    ])->assertSessionHasNoErrors();
}

/**
 * Jurnal per sumber → baris [kode akun, debit, kredit] (sesudah antrean diproses lewat halaman jurnal).
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function fnlLines(string $source, string $sourceId): array
{
    $journal = DB::table('fn_journals')->where('source', $source)->where('source_id', $sourceId)->first();

    if ($journal === null) {
        return [];
    }

    return DB::table('fn_journal_lines as l')
        ->join('fn_ledger_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.journal_id', $journal->id)
        ->orderBy('l.line_no')
        ->get(['a.code', 'l.debit', 'l.credit'])
        ->map(fn ($line) => [(string) $line->code, (int) $line->debit, (int) $line->credit])
        ->all();
}

function fnlInvoice(string $period): string
{
    return (string) DB::table('fn_invoices')->where('period', $period)->value('id');
}

test('jurnal otomatis: tagihan (akrual + potongan), kuitansi, tabungan, pemasukan, mutasi antar-kas, saldo rekening baru; selalu seimbang', function () {
    fnlActivate($this);
    fnlGenerate($this, '2026-10', '2026-12');

    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '270.000', 'paid_on' => '2026-11-05', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '50000', 'entry_date' => '2026-11-04', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-11-02', 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => '1000000', 'description' => 'Dana BOS tahap 2',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-11-03', 'amount' => '500000', 'description' => 'Setoran SPP',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-accounts.store'), [
        'kas' => $this->smp->id, 'name' => 'BSI', 'bank_name' => 'BSI', 'opening_balance' => '2000000', 'opening_date' => '2026-11-01',
    ])->assertSessionHasNoErrors();

    expect(DB::table('fn_ledger_queue')->count())->toBeGreaterThan(0);

    $this->get(route('finance.journals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/journals')
            ->where('settings.active', true)
            ->where('settings.pending', 0)
            ->where('scope', 'semua')
            ->where('journals.total', 8));

    expect(DB::table('fn_ledger_queue')->count())->toBe(0);

    // Tagihan Okt (sebelum tanggal mulai) tidak dijurnal; Nov & Des: Dr Piutang 135.000 + Dr Potongan 15.000 / Cr Pendapatan 150.000.
    expect(fnlLines('TAGIHAN', fnlInvoice('2026-10-01')))->toBe([])
        ->and(fnlLines('TAGIHAN', fnlInvoice('2026-11-01')))->toBe([['1-1400', 135000, 0], ['4-1900', 15000, 0], ['4-1100', 0, 150000]])
        ->and(DB::table('fn_journals')->where('source_id', fnlInvoice('2026-12-01'))->value('journal_date'))->toBe('2026-12-01')
        ->and(DB::table('fn_journals')->where('source_id', fnlInvoice('2026-11-01'))->value('description'))->toBe('Tagihan SPP — November 2026 — Ahmad Fauzi');

    $payment = (string) DB::table('fn_payments')->value('id');
    $mirror = (string) DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->where('source_id', $payment)->value('id');
    $deposit = (string) DB::table('fn_cash_entries')->where('source', 'TABUNGAN')->value('id');
    $bos = (string) DB::table('fn_cash_entries')->where('source', 'MANUAL')->value('id');
    $transfer = (string) DB::table('fn_cash_transfers')->value('id');
    $bank = fnlCashAccount($this->smp->id, 'BANK');

    expect(fnlLines('KAS', $mirror))->toBe([['1-1100', 270000, 0], ['1-1400', 0, 270000]])
        ->and(fnlLines('KAS', $deposit))->toBe([['1-1300', 50000, 0], ['2-1100', 0, 50000]])
        ->and(fnlLines('KAS', $bos))->toBe([['1-1100', 1000000, 0], ['4-2100', 0, 1000000]])
        ->and(fnlLines('MUTASI', $transfer))->toBe([['1-9100', 500000, 0], ['1-1100', 0, 500000], ['1-1100', 500000, 0], ['1-9100', 0, 500000]])
        ->and(fnlLines('SALDO_REKENING', $bank))->toBe([['1-1200', 2000000, 0], ['3-1000', 0, 2000000]])
        ->and(fnlLines('SALDO_REKENING', $this->smpCash))->toBe([]);

    // Mutasi antar-kas: RAK di kedua kas (lembaga & Yayasan), dimensi lembaga ikut baris.
    $transferLines = DB::table('fn_journal_lines')->where('journal_id', DB::table('fn_journals')->where('source_id', $transfer)->value('id'))->orderBy('line_no')->get();
    expect($transferLines->pluck('organization_id')->all())->toBe([$this->smp->id, $this->smp->id, null, null])
        ->and($transferLines->pluck('cash_account_id')->all())->toBe([null, $this->smpCash, $this->yayasanCash, null]);

    // Saldo awal SMP (PB-2a): Kas tunai Rp 1.000.000 per 1 Okt + piutang tagihan Oktober Rp 135.000 → aset neto.
    expect(fnlLines('SALDO_AWAL', $this->smp->id))->toBe([['1-1100', 1000000, 0], ['1-1400', 135000, 0], ['3-1000', 0, 1135000]]);

    // Nomor JU-<tahun>-<urut>, unik.
    expect(DB::table('fn_journals')->pluck('number')->every(fn ($number) => preg_match('/^JU-2026-\d{6}$/', $number) === 1))->toBeTrue()
        ->and(DB::table('fn_journals')->distinct()->count('number'))->toBe(8);

    // Semua jurnal seimbang (constraint trigger dicek sekarang, bukan saat COMMIT).
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect((int) DB::table('fn_journal_lines')->sum('debit'))->toBe((int) DB::table('fn_journal_lines')->sum('credit'));

    // Saring lingkup & sumber.
    $this->get(route('finance.journals.index', ['lingkup' => 'yayasan']))
        ->assertInertia(fn (Assert $page) => $page->where('journals.total', 1)->where('journals.rows.0.source', 'MUTASI'));
    $this->get(route('finance.journals.index', ['sumber' => 'TAGIHAN', 'bulan' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page->where('journals.total', 1)->where('months.0', ['value' => '2026-12', 'label' => 'Desember 2026']));
    $this->get(route('finance.journals.index', ['cari' => 'bos']))
        ->assertInertia(fn (Assert $page) => $page->where('journals.total', 1));

    // Rincian + tautan ke kuitansi.
    $journal = (string) DB::table('fn_journals')->where('source_id', $mirror)->value('id');
    $this->get(route('finance.journals.show', ['journal' => $journal]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/journal-show')
            ->where('journal.source_label', 'Transaksi kas')
            ->where('lines.0.account_code', '1-1100')
            ->where('lines.0.organization', 'SMP Unit 3')
            ->where('lines.0.cash_account', 'Kas tunai')
            ->where('source_url', '/keuangan/kuitansi/'.$payment));
});

test('batal sumber → jurnal BATAL; ubah pemetaan & tanggal mulai lalu susun ulang; susun ulang aman diulang', function () {
    fnlActivate($this);
    fnlGenerate($this, '2026-10', '2026-12');
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '135000', 'paid_on' => '2026-11-05', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();

    $payment = (string) DB::table('fn_payments')->value('id');
    $mirror = (string) DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->value('id');
    $november = fnlInvoice('2026-11-01');
    $numberBefore = DB::table('fn_journals')->where('source_id', $november)->value('number');

    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Salah siswa'])->assertSessionHasNoErrors();
    $this->post(route('finance.invoices.void', ['invoice' => fnlInvoice('2026-12-01')]), ['reason' => 'Dobel'])->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index', ['status' => 'BATAL']))
        ->assertInertia(fn (Assert $page) => $page->where('journals.total', 2));

    expect(DB::table('fn_journals')->where('source_id', $mirror)->value('void_reason'))->toBe('Pembayaran dibatalkan: Salah siswa')
        ->and(DB::table('fn_journals')->where('source_id', fnlInvoice('2026-12-01'))->value('void_reason'))->toBe('Tagihan dibatalkan: Dobel')
        ->and(fnlLines('KAS', $mirror))->toBe([['1-1100', 135000, 0], ['1-1400', 0, 135000]]);

    // Pemetaan baru: SPP → akun "Pendapatan SPP". Jurnal lama tetap sampai disusun ulang.
    $this->post(route('finance.ledger-accounts.store'), [
        'code' => '4-1200', 'name' => 'Pendapatan SPP', 'group' => 'PENDAPATAN', 'parent_id' => fnlAccount('4-0000'), 'cash_flow_activity' => 'OPERASI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.ledger-mappings.set'), ['kind' => 'jenis-biaya', 'item_id' => $this->spp->id, 'account_id' => fnlAccount('4-1200')])->assertSessionHasNoErrors();

    $this->get(route('finance.ledger-settings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.needs_rebuild', true)->where('mappings.jenis-biaya.0.account_id', fnlAccount('4-1200')));
    expect(fnlLines('TAGIHAN', $november)[2])->toBe(['4-1100', 0, 150000]);

    fnlRebuild($this);
    expect(fnlLines('TAGIHAN', $november)[2])->toBe(['4-1200', 0, 150000])
        ->and(DB::table('fn_journals')->where('source_id', $november)->value('number'))->toBe($numberBefore)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::JOURNALS_REBUILT)->count())->toBe(2);

    // Tanggal mulai dimundurkan → tagihan Oktober ikut dijurnal; dimajukan lagi → jurnal Oktober dihapus.
    fnlActivate($this, '2026-10-01');
    expect(fnlLines('TAGIHAN', fnlInvoice('2026-10-01')))->toBe([['1-1400', 135000, 0], ['4-1900', 15000, 0], ['4-1200', 0, 150000]])
        ->and(fnlLines('SALDO_REKENING', $this->smpCash))->toBe([['1-1100', 1000000, 0], ['3-1000', 0, 1000000]]);

    fnlActivate($this, '2026-11-01');
    expect(fnlLines('TAGIHAN', fnlInvoice('2026-10-01')))->toBe([])
        ->and(fnlLines('SALDO_REKENING', $this->smpCash))->toBe([]);

    // Susun ulang paksa tanpa perubahan: hasil sama.
    $snapshot = DB::table('fn_journal_lines')->orderBy('id')->get(['journal_id', 'account_id', 'debit', 'credit'])->toArray();
    fnlRebuild($this, force: true);
    expect(DB::table('fn_journal_lines')->orderBy('journal_id')->orderBy('line_no')->count())->toBe(count($snapshot));

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

test('COA bawaan & pemetaan: validasi, akun sistem terkunci, pemetaan yang tidak cocok ditolak', function () {
    $this->get(route('finance.ledger-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/ledger-settings')
            ->where('settings.active', false)
            ->has('accounts', 33)
            ->where('accounts.1.code', '1-1100')
            ->where('accounts.1.system_key', 'CASH')
            ->where('accounts.4.is_locked', true));

    // Kategori kas bawaan sudah terpetakan.
    expect(DB::table('fn_cash_categories')->where('code', 'ATK')->value('ledger_account_id'))->toBe(fnlAccount('5-1200'))
        ->and(DB::table('fn_cash_categories')->where('code', 'PEMBAYARAN-SISWA')->value('ledger_account_id'))->toBeNull();

    $store = route('finance.ledger-accounts.store');
    $this->post($store, ['code' => 'A-1', 'name' => 'X', 'group' => 'BEBAN'])->assertSessionHasErrors('code');
    $this->post($store, ['code' => '5-1200', 'name' => 'X', 'group' => 'BEBAN'])->assertSessionHasErrors(['code' => 'Kode 5-1200 sudah dipakai akun lain.']);
    $this->post($store, ['code' => '5-1800', 'name' => 'X', 'group' => 'BEBAN', 'parent_id' => fnlAccount('4-0000')])->assertSessionHasErrors('parent_id');
    $this->post($store, ['code' => '5-1800', 'name' => 'X', 'group' => 'BEBAN', 'is_cash' => '1'])->assertSessionHasErrors('is_cash');
    $this->post($store, ['code' => '5-1800', 'name' => '', 'group' => 'BEBAN'])->assertSessionHasErrors('name');
    $this->post($store, ['code' => '1-1210', 'name' => 'Bank BSI', 'group' => 'ASET', 'parent_id' => fnlAccount('1-1000'), 'is_cash' => '1'])->assertSessionHasNoErrors();

    expect(DB::table('fn_ledger_accounts')->where('code', '1-1210')->value('is_cash'))->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::LEDGER_ACCOUNT_CREATED)->count())->toBe(1);

    // Kunci & yang dipetakan tidak bisa dinonaktifkan; judul dengan anak aktif juga tidak.
    $this->post(route('finance.ledger-accounts.deactivate', ['account' => fnlAccount('1-1400')]))->assertSessionHasErrors(['form' => 'Akun ini dipakai jurnal otomatis, jadi tidak bisa dinonaktifkan.']);
    $this->post(route('finance.ledger-accounts.deactivate', ['account' => fnlAccount('5-1200')]))->assertSessionHasErrors(['form' => 'Akun ini masih dipetakan ke kategori kas. Ubah pemetaannya dulu.']);
    $this->post(route('finance.ledger-accounts.deactivate', ['account' => fnlAccount('5-0000')]))->assertSessionHasErrors('form');
    $this->post(route('finance.ledger-accounts.deactivate', ['account' => fnlAccount('5-1700')]))->assertSessionHasNoErrors();
    $this->patch(route('finance.ledger-accounts.update', ['account' => fnlAccount('5-1600')]), ['name' => 'Beban gaji & tunjangan', 'parent_id' => fnlAccount('5-0000'), 'cash_flow_activity' => 'OPERASI'])->assertSessionHasNoErrors();
    expect(DB::table('fn_ledger_accounts')->where('code', '5-1600')->value('name'))->toBe('Beban gaji & tunjangan');

    // Pemetaan: rekening kas → hanya akun kas; Titipan & kategori sistem tidak bisa; kategori KELUAR → beban/aset.
    $set = route('finance.ledger-mappings.set');
    $this->post($set, ['kind' => 'rekening', 'item_id' => $this->smpCash, 'account_id' => fnlAccount('4-1100')])->assertSessionHasErrors(['account_id' => 'Pilih akun kas/bank yang aktif.']);
    $this->post($set, ['kind' => 'rekening', 'item_id' => $this->smpCash, 'account_id' => fnlAccount('1-1300')])->assertSessionHasErrors('account_id');
    $this->post($set, ['kind' => 'rekening', 'item_id' => fnlCashAccount($this->smp->id, 'TITIPAN'), 'account_id' => fnlAccount('1-1100')])->assertSessionHasErrors('form');
    $this->post($set, ['kind' => 'kategori', 'item_id' => (string) DB::table('fn_cash_categories')->where('code', 'PEMBAYARAN-SISWA')->value('id'), 'account_id' => fnlAccount('4-1100')])->assertSessionHasErrors('form');
    $this->post($set, ['kind' => 'kategori', 'item_id' => (string) DB::table('fn_cash_categories')->where('code', 'ATK')->value('id'), 'account_id' => fnlAccount('4-1100')])->assertSessionHasErrors('account_id');
    $this->post($set, ['kind' => 'kategori', 'item_id' => (string) DB::table('fn_cash_categories')->where('code', 'ATK')->value('id'), 'account_id' => fnlAccount('1-2200')])->assertSessionHasNoErrors();
    $this->post($set, ['kind' => 'jenis-biaya', 'item_id' => $this->spp->id, 'account_id' => fnlAccount('4-1900')])->assertSessionHasErrors('account_id');
    $this->post($set, ['kind' => 'rekening', 'item_id' => $this->smpCash, 'account_id' => fnlAccount('1-1210')])->assertSessionHasNoErrors();
    $this->post($set, ['kind' => 'rekening', 'item_id' => $this->smpCash, 'account_id' => ''])->assertSessionHasNoErrors();

    expect(DB::table('fn_cash_accounts')->where('id', $this->smpCash)->value('ledger_account_id'))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::LEDGER_MAPPING_SET)->count())->toBe(3);

    // Periode: wajib tanggal 1.
    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-05', 'fiscal_start_month' => '1'])
        ->assertSessionHasErrors(['start_date' => 'Tanggal mulai pembukuan harus tanggal 1 (awal bulan).']);
    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-01', 'fiscal_start_month' => '13'])->assertSessionHasErrors('fiscal_start_month');
    $this->post(route('finance.ledger-rebuild'))->assertSessionHasErrors(['form' => 'Atur tanggal mulai pembukuan dulu.']);
});

test('antrean hanya terisi bila pembukuan aktif; jurnal tidak seimbang ditolak database; perintah artisan menyusun jurnal', function () {
    $insert = fn (string $date) => DB::table('fn_cash_entries')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp->id, 'account_id' => $this->smpCash,
        'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'DONASI')->value('id'), 'entry_date' => $date, 'direction' => 'MASUK',
        'amount' => 250000, 'description' => 'Infak wali murid', 'source' => 'MANUAL', 'status' => 'SAH', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $insert('2026-11-02');
    expect(DB::table('fn_ledger_queue')->count())->toBe(0);

    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-01', 'fiscal_start_month' => '7'])->assertSessionHasNoErrors();
    $insert('2026-11-03');
    expect(DB::table('fn_ledger_queue')->count())->toBe(1);

    $this->artisan('finance:ledger-process')->assertSuccessful();
    expect(DB::table('fn_ledger_queue')->count())->toBe(0)
        ->and(DB::table('fn_journals')->where('source', 'KAS')->count())->toBe(2)
        ->and(DB::table('fn_ledger_settings')->value('fiscal_start_month'))->toBe(7);

    // Jurnal yang debit ≠ kredit ditolak database.
    $journalId = (string) Str::uuid7();
    DB::table('fn_journals')->insert([
        'id' => $journalId, 'tenant_id' => $this->tenant->id, 'number' => 'JU-2026-999999', 'journal_date' => '2026-11-05', 'description' => 'Uji',
        'source' => 'MANUAL', 'amount' => 1000, 'status' => 'SAH', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('fn_journal_lines')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'journal_id' => $journalId, 'line_no' => 1, 'account_id' => fnlAccount('5-9100'), 'debit' => 1000, 'credit' => 0,
    ]);

    expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toThrow(QueryException::class, 'tidak seimbang');
});

test('hak akses: bendahara tidak melihat pembukuan; kepala lembaga hanya lembaganya; pengaturan hanya Admin tingkat Yayasan', function () {
    fnlActivate($this);
    fnlGenerate($this, '2026-11', '2026-11');
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-11-03', 'amount' => '100000', 'description' => 'Setoran',
    ])->assertSessionHasNoErrors();
    $this->get(route('finance.cash.index', ['kas' => $this->mts->id]))->assertOk();
    $this->patch(route('finance.cash-accounts.update', ['account' => fnlCashAccount($this->mts->id, 'TUNAI')]), ['opening_balance' => '0', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->mts->id, 'account_id' => fnlCashAccount($this->mts->id, 'TUNAI'), 'entry_date' => '2026-11-02',
        'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'DONASI')->value('id'), 'amount' => '75000', 'description' => 'Infak MTs',
    ])->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();
    $mtsJournal = (string) DB::table('fn_journals')->where('description', 'Infak MTs')->value('id');

    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->actingAs($bendahara)->get(route('finance.journals.index'))->assertForbidden();
    $this->actingAs($bendahara)->get(route('finance.ledger-settings.index'))->assertForbidden();

    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->actingAs($kepala)->get(route('finance.journals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scopes', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']])
            ->where('scope', $this->smp->id)
            ->where('canManage', false)
            ->where('journals.total', 3));
    $this->actingAs($kepala)->get(route('finance.journals.index', ['lingkup' => 'semua']))
        ->assertInertia(fn (Assert $page) => $page->where('scope', $this->smp->id));
    $this->actingAs($kepala)->get(route('finance.journals.show', ['journal' => $mtsJournal]))->assertNotFound();
    $this->actingAs($kepala)->get(route('finance.ledger-settings.index'))->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.ledger-rebuild'))->assertForbidden();

    $this->actingAs(DevAccounts::user('pimpinan.ponpes'))->get(route('finance.journals.index'))->assertOk();
});
