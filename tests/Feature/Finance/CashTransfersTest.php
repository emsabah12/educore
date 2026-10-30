<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Mutasi & laporan arus kas KS-4 (PRD-004). "Hari ini" Jumat 30 Okt 2026. Saldo awal per 1 Okt:
 *   SMP Kas tunai Rp 2.000.000, SMP BSI Rp 0, Yayasan Kas tunai Rp 0.
 *   05 Okt  BOS Rp 1.000.000 → SMP Kas tunai
 *   10 Okt  Mutasi SMP Kas tunai → SMP BSI Rp 1.500.000 (setor ke bank)
 *   15 Okt  Mutasi SMP BSI → Yayasan Kas tunai Rp 1.000.000 (setoran)
 *   20 Okt  Pengeluaran ATK Rp 200.000 dari SMP Kas tunai (realisasi KS-3, disimulasikan)
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS');
    $this->actingAs($this->admin);

    foreach (['yayasan', $this->smp->id, $this->mts->id] as $book) {
        $this->get(route('finance.cash.index', ['kas' => $book]))->assertOk();
    }

    $this->smpCash = fntAccount($this->smp->id, 'Kas tunai');
    $this->yayasanCash = fntAccount(null, 'Kas tunai');
    $this->mtsCash = fntAccount($this->mts->id, 'Kas tunai');

    foreach ([[$this->smpCash, '2000000'], [$this->yayasanCash, '0'], [$this->mtsCash, '0']] as [$account, $opening]) {
        $this->patch(route('finance.cash-accounts.update', ['account' => $account]), ['opening_balance' => $opening, 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
    }

    $this->post(route('finance.cash-accounts.store'), [
        'kas' => $this->smp->id, 'name' => 'BSI', 'bank_name' => 'BSI', 'opening_balance' => '0', 'opening_date' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    $this->smpBank = fntAccount($this->smp->id, 'BSI');

    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-10-05', 'category_id' => fntCategory('BOS'), 'amount' => '1000000', 'description' => 'Dana BOS',
    ])->assertSessionHasNoErrors();

    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->smpCash, $this->smpBank, '2026-10-10', '1.500.000', 'Setor tunai ke bank'))->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->smpBank, $this->yayasanCash, '2026-10-15', '1000000', 'Setoran SPP'))->assertSessionHasNoErrors();

    DB::table('fn_cash_entries')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp->id, 'account_id' => $this->smpCash,
        'category_id' => fntCategory('ATK'), 'entry_date' => '2026-10-20', 'direction' => 'KELUAR', 'amount' => 200000,
        'description' => 'Pengeluaran — Kertas', 'source' => 'PENGELUARAN', 'status' => 'SAH', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function fntAccount(?string $organizationId, string $name): string
{
    return (string) DB::table('fn_cash_accounts')
        ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
        ->where('name', $name)->value('id');
}

function fntCategory(string $code): string
{
    return (string) DB::table('fn_cash_categories')->where('code', $code)->value('id');
}

/**
 * @return array<string, string>
 */
function fntTransfer(string $from, string $to, string $date, string $amount, string $description): array
{
    return ['account_id' => $from, 'to_account_id' => $to, 'entry_date' => $date, 'amount' => $amount, 'description' => $description];
}

test('mutasi: saldo pindah, dua baris kas bertanda mutasi, tidak dihitung sebagai pemasukan/pengeluaran', function () {
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id, 'bulan' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.balance', 1300000)
            ->where('accounts.1.balance', 500000)
            ->where('ledger.total_in', 1000000)
            ->where('ledger.total_out', 200000)
            ->where('ledger.transfer_in', 1500000)
            ->where('ledger.transfer_out', 2500000)
            ->where('ledger.closing', 1800000)
            ->where('transferTargets', [
                ['value' => $this->smpCash, 'label' => 'Kas tunai'],
                ['value' => $this->smpBank, 'label' => 'BSI'],
                ['value' => $this->yayasanCash, 'label' => 'Kas tunai — Kas Yayasan'],
            ]));

    $this->get(route('finance.cash.index', ['kas' => 'yayasan']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.balance', 1000000)
            ->where('ledger.rows.1.description', 'Mutasi dari BSI (SMP Unit 3) — Setoran SPP')
            ->where('ledger.rows.1.category', 'Mutasi masuk')
            ->where('ledger.rows.1.source', 'MUTASI')
            ->where('ledger.total_in', 0)
            ->has('transferTargets', 4));

    expect(DB::table('fn_cash_transfers')->count())->toBe(2)
        ->and(DB::table('fn_cash_entries')->where('source', 'MUTASI')->count())->toBe(4)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::CASH_TRANSFER_RECORDED)->count())->toBe(2);
});

test('mutasi ditolak: saldo kurang, rekening sama, antar-lembaga, sebelum saldo awal; batal mutasi & saldo tujuan minus', function () {
    $store = route('finance.cash-transfers.store');

    $this->post($store, fntTransfer($this->smpBank, $this->yayasanCash, '2026-10-30', '500001', 'Setoran'))
        ->assertSessionHasErrors(['amount' => 'Saldo BSI tidak cukup (Rp 500.000).']);
    $this->post($store, fntTransfer($this->smpCash, $this->smpCash, '2026-10-30', '1000', 'Salah'))->assertSessionHasErrors('to_account_id');
    $this->post($store, fntTransfer($this->smpCash, $this->mtsCash, '2026-10-30', '1000', 'Ke MTs'))
        ->assertSessionHasErrors(['to_account_id' => 'Mutasi antar-lembaga harus lewat kas Yayasan.']);
    $this->post($store, fntTransfer($this->smpCash, $this->yayasanCash, '2026-09-30', '1000', 'Lama'))->assertSessionHasErrors('entry_date');
    $this->post($store, fntTransfer($this->smpCash, $this->yayasanCash, '2026-10-30', '1000', 'ab'))->assertSessionHasErrors('description');

    // Yayasan → MTs (subsidi) boleh.
    $this->post($store, fntTransfer($this->yayasanCash, $this->mtsCash, '2026-10-30', '250000', 'Subsidi MTs'))->assertSessionHasNoErrors();

    $toBank = (string) DB::table('fn_cash_transfers')->where('description', 'Setor tunai ke bank')->value('id');
    $toYayasan = (string) DB::table('fn_cash_transfers')->where('description', 'Setoran SPP')->value('id');

    // Uang di BSI sudah disetor ke Yayasan → membatalkan setor-ke-bank membuat BSI minus.
    $this->post(route('finance.cash-transfers.void', ['transfer' => $toBank]), ['reason' => 'Salah'])
        ->assertSessionHasErrors(['form' => 'Pembatalan ditolak: saldo BSI akan menjadi minus.']);
    $this->post(route('finance.cash-transfers.void', ['transfer' => $toYayasan]), ['reason' => ''])->assertSessionHasErrors('reason');
    // Sebagian setoran sudah dipakai untuk subsidi MTs → kas Yayasan akan minus.
    $this->post(route('finance.cash-transfers.void', ['transfer' => $toYayasan]), ['reason' => 'Belum disetor'])
        ->assertSessionHasErrors(['form' => 'Pembatalan ditolak: saldo Kas tunai akan menjadi minus.']);
    $subsidy = (string) DB::table('fn_cash_transfers')->where('description', 'Subsidi MTs')->value('id');
    $this->post(route('finance.cash-transfers.void', ['transfer' => $subsidy]), ['reason' => 'Batal subsidi'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.void', ['transfer' => $toYayasan]), ['reason' => 'Belum disetor'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.void', ['transfer' => $toYayasan]), ['reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Mutasi ini sudah dibatalkan.']);

    expect(DB::table('fn_cash_entries')->where('source_id', $toYayasan)->pluck('status')->unique()->all())->toBe(['BATAL']);

    // Baris mutasi tidak bisa dibatalkan lewat "batalkan transaksi" biasa.
    $entry = (string) DB::table('fn_cash_entries')->where('source_id', $toBank)->where('direction', 'KELUAR')->value('id');
    $this->post(route('finance.cash-entries.void', ['entry' => $entry]), ['reason' => 'X'])->assertSessionHasErrors('form');
});

test('hak akses mutasi: bendahara hanya dari kas lembaganya ke kas sendiri/Yayasan', function () {
    CoreFixtures::syncAccess();
    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');

    $this->flushSession();
    $this->actingAs($bendahara)->get(route('finance.cash.index'))
        ->assertInertia(fn (Assert $page) => $page->has('transferTargets', 3));
    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->smpCash, $this->yayasanCash, '2026-10-30', '100000', 'Setoran kedua'))->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->yayasanCash, $this->smpCash, '2026-10-30', '100000', 'Ambil'))->assertNotFound();
    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->mtsCash, $this->yayasanCash, '2026-10-30', '1', 'X'))->assertNotFound();

    $this->flushSession();
    $this->actingAs($kepala)->get(route('finance.cash.index'))->assertInertia(fn (Assert $page) => $page->where('transferTargets', []));
    $this->post(route('finance.cash-transfers.store'), fntTransfer($this->smpCash, $this->yayasanCash, '2026-10-30', '1', 'X'))->assertForbidden();
    $transfer = (string) DB::table('fn_cash_transfers')->where('description', 'Setoran kedua')->value('id');
    $this->post(route('finance.cash-transfers.void', ['transfer' => $transfer]), ['reason' => 'X'])->assertForbidden();
});

test('laporan arus kas: satu kas & semua kas, bulan & tahun; unduh .xlsx', function () {
    $this->get(route('finance.cash-report.index', ['kas' => $this->smp->id, 'periode' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cash-report')
            ->where('books.0', ['value' => 'semua', 'label' => 'Semua kas'])
            ->where('bookKey', $this->smp->id)
            ->where('period', '2026-10')
            ->where('report.opening', 2000000)
            ->where('report.income', [['category' => 'Dana BOS', 'amount' => 1000000]])
            ->where('report.income_total', 1000000)
            ->where('report.expense', [['category' => 'ATK & perlengkapan', 'amount' => 200000]])
            ->where('report.transfer_in', 1500000)
            ->where('report.transfer_out', 2500000)
            ->where('report.closing', 1800000)
            ->where('report.months', [])
            ->where('report.books', []));

    // Semua kas (bawaan), setahun: mutasi antar-kas saling meniadakan.
    $this->get(route('finance.cash-report.index', ['periode' => '2026']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookKey', 'semua')
            ->where('report.from', '2026-01-01')
            ->where('report.opening', 2000000)
            ->where('report.transfer_in', 2500000)
            ->where('report.transfer_out', 2500000)
            ->where('report.closing', 2800000)
            ->has('report.months', 12)
            ->where('report.months.9', ['month' => 'Oktober 2026', 'income' => 1000000, 'expense' => 200000])
            ->has('report.books', 3)
            ->where('report.books.0.closing', 1000000));

    // Periode tidak dikenal → bulan ini.
    $this->get(route('finance.cash-report.index', ['periode' => '2030']))->assertInertia(fn (Assert $page) => $page->where('period', '2026-10'));

    $response = $this->get(route('finance.cash-report.export', ['kas' => 'semua', 'periode' => '2026']))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('laporan-kas-semua-kas-2026.xlsx');

    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Ringkasan', 'Per bulan', 'Per kas', 'Transaksi'])
        ->and($sheets['Ringkasan'][0][0])->toBe('Laporan arus kas semua kas — Tahun 2026')
        ->and(end($sheets['Ringkasan']))->toBe(['Saldo akhir', 2800000])
        ->and(count($sheets['Transaksi']))->toBe(2 + 6);

    $this->get(route('finance.cash-report.export', ['kas' => $this->smp->id, 'periode' => '2030']))->assertNotFound();

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.cash-report.index'))->assertForbidden();
});
