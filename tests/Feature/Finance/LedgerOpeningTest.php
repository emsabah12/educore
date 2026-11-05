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
 * Pembukuan PB-2a (PRD-007 §5–§6): saldo awal & jurnal manual. "Hari ini" Kamis 5 Nov 2026, pembukuan mulai 1 Nov 2026.
 * Sebelum tanggal mulai (SMP Unit 3):
 *   Kas tunai SMP saldo awal Rp 1.000.000 per 1 Okt; BOS +Rp 200.000 (10 Okt); kuitansi SPP Sep +Rp 150.000 (20 Okt);
 *   mutasi ke Kas Yayasan −Rp 100.000 (25 Okt); setoran tabungan Rp 40.000 (15 Okt, rekening Titipan).
 *   Tagihan SPP Sep & Okt Rp 150.000/bulan → piutang awal Rp 300.000 − Rp 150.000 = Rp 150.000.
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
    $enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-09', 'to' => '2026-11',
    ])->assertSessionHasNoErrors();

    foreach (['yayasan', $this->smp->id] as $book) {
        $this->get(route('finance.cash.index', ['kas' => $book]))->assertOk();
    }

    $this->smpCash = fnjCashAccount($this->smp->id, 'TUNAI');
    $this->yayasanCash = fnjCashAccount(null, 'TUNAI');
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->smpCash]), ['opening_balance' => '1000000', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->yayasanCash]), ['opening_balance' => '0', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();

    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-10-10', 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => '200000', 'description' => 'Dana BOS tahap 1',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $enrollment, 'amount' => '40000', 'entry_date' => '2026-10-15', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $enrollment, 'amount' => '150000', 'paid_on' => '2026-10-20', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-10-25', 'amount' => '100000', 'description' => 'Setoran SPP',
    ])->assertSessionHasNoErrors();

    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-01', 'fiscal_start_month' => '1'])->assertSessionHasNoErrors();
    $this->artisan('finance:ledger-process')->assertSuccessful();
});

function fnjCashAccount(?string $organizationId, string $kind): string
{
    return (string) DB::table('fn_cash_accounts')
        ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
        ->where('kind', $kind)->orderBy('created_at')->value('id');
}

function fnjAccount(string $code): string
{
    return (string) DB::table('fn_ledger_accounts')->where('code', $code)->value('id');
}

/**
 * Baris jurnal saldo awal pemilik ini → [kode akun, debit, kredit].
 *
 * @return list<array{0: string, 1: int, 2: int}>
 */
function fnjOpening(string $owner): array
{
    $journal = DB::table('fn_journals')->where('source', 'SALDO_AWAL')->where('source_id', $owner)->first();

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

/**
 * @param  list<array{0: string, 1: string|null, 2: string, 3: string}>  $lines  [kode akun, lembaga, debit, kredit]
 * @return array<string, mixed>
 */
function fnjManual(string $date, string $description, array $lines): array
{
    return [
        'date' => $date,
        'description' => $description,
        'lines' => array_map(fn (array $line) => ['account_id' => fnjAccount($line[0]), 'organization_id' => $line[1] ?? '', 'debit' => $line[2], 'credit' => $line[3]], $lines),
    ];
}

test('saldo awal otomatis per lembaga & Yayasan: kas, titipan, piutang, selisih ke aset neto', function () {
    expect(fnjOpening($this->smp->id))->toBe([
        ['1-1100', 1250000, 0],
        ['1-1300', 40000, 0],
        ['1-1400', 150000, 0],
        ['2-1100', 0, 40000],
        ['3-1000', 0, 1400000],
    ])
        ->and(fnjOpening($this->tenant->id))->toBe([['1-1100', 100000, 0], ['3-1000', 0, 100000]])
        ->and(DB::table('fn_journals')->where('source', 'SALDO_AWAL')->where('source_id', $this->smp->id)->value('description'))->toBe('Saldo awal SMP Unit 3 per 1 November 2026')
        ->and(DB::table('fn_journals')->where('source', 'SALDO_AWAL')->where('source_id', $this->smp->id)->value('journal_date'))->toBe('2026-11-01')
        // Lembaga tanpa saldo sama sekali tidak mendapat jurnal saldo awal.
        ->and(DB::table('fn_journals')->where('source', 'SALDO_AWAL')->count())->toBe(2);

    // Transaksi sebelum tanggal mulai tidak dijurnal sendiri (sudah termasuk saldo awal); tagihan November dijurnal.
    expect(DB::table('fn_journals')->where('source', '!=', 'SALDO_AWAL')->count())->toBe(1);

    $this->get(route('finance.ledger-settings.index', ['tab' => 'saldo-awal', 'lingkup' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('openingScope', $this->smp->id)
            ->where('openingScopes.0', ['value' => 'yayasan', 'label' => 'Kas Yayasan'])
            ->has('opening.lines', 5)
            ->where('opening.lines.0.cash_account', 'Kas tunai')
            ->where('opening.items', [])
            ->where('openingAccounts', fn ($accounts) => collect($accounts)->pluck('label')->doesntContain('1-1100 · Kas tunai')
                && collect($accounts)->pluck('label')->doesntContain('3-1000 · Aset neto tanpa pembatasan')
                && collect($accounts)->pluck('label')->contains('3-2000 · Aset neto dengan pembatasan')));

    // Transaksi lama berubah (kuitansi Oktober dibatalkan) → saldo awal disusun ulang lewat antrean.
    $payment = (string) DB::table('fn_payments')->value('id');
    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Salah catat'])->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();

    expect(fnjOpening($this->smp->id))->toBe([
        ['1-1100', 1100000, 0],
        ['1-1300', 40000, 0],
        ['1-1400', 300000, 0],
        ['2-1100', 0, 40000],
        ['3-1000', 0, 1400000],
    ]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

test('saldo tambahan akuntan: tambah, ubah, hapus; aset neto menyesuaikan; akun yang tidak boleh ditolak', function () {
    $store = route('finance.opening-items.store');
    $base = ['lingkup' => $this->smp->id, 'side' => 'DEBIT', 'amount' => '500.000.000', 'note' => 'Gedung sekolah'];

    $this->post($store, ['account_id' => fnjAccount('1-1100')] + $base)->assertSessionHasErrors('account_id');
    $this->post($store, ['account_id' => fnjAccount('1-1400')] + $base)->assertSessionHasErrors('account_id');
    $this->post($store, ['account_id' => fnjAccount('3-1000')] + $base)->assertSessionHasErrors('account_id');
    $this->post($store, ['account_id' => fnjAccount('5-0000')] + $base)->assertSessionHasErrors('account_id');
    $this->post($store, ['account_id' => fnjAccount('1-2100'), 'amount' => '0'] + $base)->assertSessionHasErrors('amount');
    $this->post($store, ['account_id' => fnjAccount('1-2100'), 'side' => 'X'] + $base)->assertSessionHasErrors('side');
    $this->post($store, ['account_id' => fnjAccount('1-2100'), 'lingkup' => (string) str()->uuid()] + $base)->assertSessionHasErrors('organization_id');

    $this->post($store, ['account_id' => fnjAccount('1-2100')] + $base)->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();

    expect(fnjOpening($this->smp->id))->toBe([
        ['1-1100', 1250000, 0],
        ['1-1300', 40000, 0],
        ['1-1400', 150000, 0],
        ['1-2100', 500000000, 0],
        ['2-1100', 0, 40000],
        ['3-1000', 0, 501400000],
    ]);

    $item = (string) DB::table('fn_opening_items')->value('id');
    $this->patch(route('finance.opening-items.update', ['item' => $item]), ['account_id' => fnjAccount('2-1900'), 'side' => 'KREDIT', 'amount' => '10000000', 'note' => 'Pinjaman koperasi'])
        ->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();

    expect(fnjOpening($this->smp->id))->toBe([
        ['1-1100', 1250000, 0],
        ['1-1300', 40000, 0],
        ['1-1400', 150000, 0],
        ['3-1000', 8600000, 0],
        ['2-1100', 0, 40000],
        ['2-1900', 0, 10000000],
    ]);

    $this->get(route('finance.ledger-settings.index', ['tab' => 'saldo-awal', 'lingkup' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('opening.items.0', ['id' => $item, 'account_id' => fnjAccount('2-1900'), 'side' => 'KREDIT', 'amount' => 10000000, 'note' => 'Pinjaman koperasi']));

    $this->delete(route('finance.opening-items.destroy', ['item' => $item]))->assertSessionHasNoErrors();
    $this->get(route('finance.journals.index'))->assertOk();

    expect(DB::table('fn_opening_items')->count())->toBe(0)
        ->and(fnjOpening($this->smp->id)[4])->toBe(['3-1000', 0, 1400000])
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::OPENING_BALANCE_SET)->count())->toBe(3);

    // Kas Yayasan juga bisa diberi saldo tambahan.
    $this->post($store, ['lingkup' => 'yayasan', 'account_id' => fnjAccount('1-2200'), 'side' => 'DEBIT', 'amount' => '25000000', 'note' => ''])->assertSessionHasNoErrors();
    $this->artisan('finance:ledger-process')->assertSuccessful();
    expect(fnjOpening($this->tenant->id))->toBe([['1-1100', 100000, 0], ['1-2200', 25000000, 0], ['3-1000', 0, 25100000]]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

test('jurnal manual: catat, validasi, batal; tidak tersentuh susun ulang', function () {
    $store = route('finance.journals.store');
    $smp = $this->smp->id;

    $this->post($store, fnjManual('2026-11-05', 'Penyusutan', [['5-1700', $smp, '1000000', ''], ['1-2900', $smp, '', '900000']]))
        ->assertSessionHasErrors(['lines' => 'Total debit (Rp 1.000.000) harus sama dengan total kredit (Rp 900.000).']);
    $this->post($store, fnjManual('2026-11-05', 'Penyusutan', [['5-1700', $smp, '1000000', '']]))->assertSessionHasErrors(['lines' => 'Jurnal minimal 2 baris.']);
    $this->post($store, fnjManual('2026-11-05', 'Koreksi kas', [['1-1100', $smp, '1000', ''], ['5-9100', $smp, '', '1000']]))->assertSessionHasErrors('lines');
    $this->post($store, fnjManual('2026-11-05', 'Koreksi', [['1-1400', $smp, '1000', ''], ['4-9100', $smp, '', '1000']]))->assertSessionHasErrors('lines');
    $this->post($store, fnjManual('2026-11-05', 'Dobel', [['5-1700', $smp, '1000', '1000'], ['1-2900', $smp, '', '1000']]))
        ->assertSessionHasErrors(['lines' => 'Baris 1: isi debit ATAU kredit (salah satu).']);
    $this->post($store, fnjManual('2026-10-31', 'Penyusutan', [['5-1700', $smp, '1000', ''], ['1-2900', $smp, '', '1000']]))->assertSessionHasErrors('date');
    $this->post($store, fnjManual('2026-12-01', 'Penyusutan', [['5-1700', $smp, '1000', ''], ['1-2900', $smp, '', '1000']]))
        ->assertSessionHasErrors(['date' => 'Tanggal jurnal paling lambat akhir bulan ini.']);
    $this->post($store, fnjManual('2026-11-05', 'ab', [['5-1700', $smp, '1000', ''], ['1-2900', $smp, '', '1000']]))->assertSessionHasErrors('description');

    $response = $this->post($store, fnjManual('2026-11-05', 'Penyusutan peralatan November', [
        ['5-1700', $smp, '1.000.000', ''],
        ['1-2900', $smp, '', '1.000.000'],
        ['', null, '', ''],
    ]))->assertSessionHasNoErrors();

    $journal = DB::table('fn_journals')->where('source', 'MANUAL')->sole();
    $response->assertRedirect('/keuangan/pembukuan/jurnal/'.$journal->id);

    expect($journal->organization_id)->toBe($smp)
        ->and((int) $journal->amount)->toBe(1000000)
        ->and($journal->number)->toMatch('/^JU-2026-\d{6}$/')
        ->and(DB::table('fn_journal_lines')->where('journal_id', $journal->id)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::JOURNAL_POSTED)->count())->toBe(1);

    $this->get(route('finance.journals.show', ['journal' => $journal->id]))
        ->assertInertia(fn (Assert $page) => $page->where('journal.source_label', 'Jurnal manual')->where('canVoid', true)->where('source_url', null));
    $this->get(route('finance.journals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.min_date', '2026-11-01')
            ->where('manual.max_date', '2026-11-30')
            ->where('manual.organizations.0', ['value' => '', 'label' => 'Yayasan'])
            ->where('manual.accounts', fn ($accounts) => collect($accounts)->pluck('label')->doesntContain('1-1100 · Kas tunai')));

    // Susun ulang paksa tidak menyentuh jurnal manual.
    $this->post(route('finance.ledger-rebuild'), ['mulai' => '1'])->assertSessionHasNoErrors();
    expect(DB::table('fn_journals')->where('id', $journal->id)->value('status'))->toBe('SAH');

    // Batal: alasan wajib; jurnal otomatis tidak bisa dibatalkan dari sini.
    $this->post(route('finance.journals.void', ['journal' => $journal->id]), ['reason' => ''])->assertSessionHasErrors('reason');
    $auto = (string) DB::table('fn_journals')->where('source', 'TAGIHAN')->value('id');
    $this->post(route('finance.journals.void', ['journal' => $auto]), ['reason' => 'Coba'])
        ->assertSessionHasErrors(['form' => 'Jurnal otomatis hanya bisa batal lewat transaksi sumbernya.']);
    $this->post(route('finance.journals.void', ['journal' => $journal->id]), ['reason' => 'Salah nominal'])->assertSessionHasNoErrors();
    $this->post(route('finance.journals.void', ['journal' => $journal->id]), ['reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Jurnal ini sudah dibatalkan.']);

    expect(DB::table('fn_journals')->where('id', $journal->id)->value('void_reason'))->toBe('Salah nominal')
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::JOURNAL_VOIDED)->count())->toBe(1);
    $this->get(route('finance.journals.show', ['journal' => $journal->id]))->assertInertia(fn (Assert $page) => $page->where('canVoid', false));

    // Kepala lembaga: lihat saja.
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->actingAs($kepala)->post($store, fnjManual('2026-11-05', 'Penyusutan', [['5-1700', $smp, '1000', ''], ['1-2900', $smp, '', '1000']]))->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.opening-items.store'), ['lingkup' => $smp, 'account_id' => fnjAccount('1-2100'), 'side' => 'DEBIT', 'amount' => '1000'])->assertForbidden();
    $this->actingAs($kepala)->get(route('finance.journals.index'))->assertInertia(fn (Assert $page) => $page->where('manual', null));
    $this->actingAs($kepala)->get(route('finance.journals.show', ['journal' => $journal->id]))->assertInertia(fn (Assert $page) => $page->where('canVoid', false));

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});
