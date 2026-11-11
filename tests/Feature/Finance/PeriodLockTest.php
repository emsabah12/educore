<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Cash\ManageExpenses;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Application\Ledger\LedgerLock;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kunci periode / tutup buku PB-4 (PRD-007 §19). "Hari ini" Sabtu 5 Des 2026, pembukuan mulai 1 Okt 2026.
 * SMP Unit 3: Ahmad Fauzi, SPP Rp 150.000 Sep–Des. Transaksi November: kuitansi SPP Sep (10 Nov), pemasukan BOS
 * (12 Nov), setoran tabungan (15 Nov), mutasi ke Kas Yayasan (20 Nov), jurnal manual (25 Nov), realisasi pengeluaran
 * (20 Nov). Kunci sampai 30 November 2026.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($this->admin);

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();
    $this->enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');

    $this->spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $this->spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->spp->id, 'from' => '2026-09', 'to' => '2026-12',
    ])->assertSessionHasNoErrors();

    foreach (['yayasan', $this->smp->id] as $book) {
        $this->get(route('finance.cash.index', ['kas' => $book]))->assertOk();
    }

    $this->smpCash = fnplCashAccount($this->smp->id);
    $this->yayasanCash = fnplCashAccount(null);
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->smpCash]), ['opening_balance' => '1000000', 'opening_date' => '2026-09-01'])->assertSessionHasNoErrors();
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->yayasanCash]), ['opening_balance' => '0', 'opening_date' => '2026-09-01'])->assertSessionHasNoErrors();

    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-10-01', 'fiscal_start_month' => '1'])->assertSessionHasNoErrors();

    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '150000', 'paid_on' => '2026-11-10', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-11-12', 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => '200000', 'description' => 'Dana BOS tahap 2',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '40000', 'entry_date' => '2026-11-15', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-11-20', 'amount' => '100000', 'description' => 'Setoran SPP',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.journals.store'), [
        'date' => '2026-11-25', 'description' => 'Penyusutan November',
        'lines' => [
            ['account_id' => fnplAccount('5-1700'), 'organization_id' => '', 'debit' => '50000', 'credit' => ''],
            ['account_id' => fnplAccount('1-2900'), 'organization_id' => '', 'debit' => '', 'credit' => '50000'],
        ],
    ])->assertSessionHasNoErrors();

    // Pengajuan pengeluaran diajukan, disetujui & direalisasi 20 November.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', BusinessClock::timezone()));
    $expenses = app(ManageExpenses::class);
    [$requester, $approver] = DB::table('memberships')->where('tenant_id', $this->tenant->id)->orderBy('id')->limit(2)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    $this->expense = $expenses->request($this->tenant->id, $this->smp->id, fnplExpenseCategory(), 'Beli spidol', '30000', null, null, $requester);
    $expenses->approve($this->tenant->id, $this->expense, '30000', null, $approver, true);
    $expenses->realize($this->tenant->id, $this->expense, $this->smpCash, '2026-11-20', '30000', null, $approver);
    $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00:00', BusinessClock::timezone()));

    $this->artisan('finance:ledger-process')->assertSuccessful();
    $this->flushSession();
});

function fnplCashAccount(?string $organizationId): string
{
    return (string) DB::table('fn_cash_accounts')
        ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
        ->where('kind', 'TUNAI')->value('id');
}

function fnplAccount(string $code): string
{
    return (string) DB::table('fn_ledger_accounts')->where('code', $code)->value('id');
}

function fnplExpenseCategory(): string
{
    return (string) DB::table('fn_cash_categories')->where('direction', 'KELUAR')->where('is_system', false)->orderBy('name')->value('id');
}

function fnplLock(mixed $test, string $through): void
{
    $test->post(route('finance.ledger-lock.lock'), ['locked_through' => $through])->assertSessionHasNoErrors();
}

test('kunci periode: pilihan bulan, kunci sampai November, audit; bulan berjalan & sebelum tanggal mulai ditolak', function () {
    $this->get(route('finance.ledger-settings.index', ['tab' => 'periode']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lock.through', null)
            ->where('lock.lock_options', [['value' => '2026-10-31', 'label' => 'Oktober 2026'], ['value' => '2026-11-30', 'label' => 'November 2026']])
            ->where('lock.unlock_options', []));

    $this->post(route('finance.ledger-lock.lock'), ['locked_through' => '2026-12-31'])
        ->assertSessionHasErrors(['locked_through' => 'Pilih bulan yang sudah berakhir, sejak bulan mulai pembukuan.']);
    $this->post(route('finance.ledger-lock.lock'), ['locked_through' => '2026-09-30'])->assertSessionHasErrors('locked_through');

    fnplLock($this, '2026-11');

    expect(DB::table('fn_ledger_settings')->value('locked_through'))->toBe('2026-11-30')
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::PERIOD_LOCKED)->count())->toBe(1);

    $this->post(route('finance.ledger-lock.lock'), ['locked_through' => '2026-10-31'])
        ->assertSessionHasErrors(['locked_through' => 'Bulan itu sudah terkunci. Pilih bulan sesudah November 2026.']);

    $this->get(route('finance.ledger-settings.index', ['tab' => 'periode']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lock.through', '2026-11-30')
            ->where('lock.through_label', '30 November 2026')
            ->where('lock.lock_options', [])
            ->where('lock.unlock_options.0', ['value' => '', 'label' => 'Buka semua (tidak ada periode terkunci)'])
            ->where('lock.unlock_options.1', ['value' => '2026-10-31', 'label' => 'Oktober 2026']));

    // Tanggal mulai & tahun buku tidak bisa diubah selama ada kunci.
    $this->post(route('finance.ledger-period.set'), ['start_date' => '2026-11-01', 'fiscal_start_month' => '1'])
        ->assertSessionHasErrors('start_date');
});

test('periode terkunci: kasir, kas, mutasi, tabungan, pengeluaran, tagihan, jurnal manual, saldo awal menolak dengan pesan jelas; transaksi Desember tetap jalan', function () {
    fnplLock($this, '2026-11-30');
    $locked = 'Tanggal 28 November 2026 sudah masuk periode tutup buku (terkunci sampai 30 November 2026). Pakai tanggal sesudahnya, atau minta pengelola pembukuan Yayasan membuka kuncinya.';

    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '150000', 'paid_on' => '2026-11-28', 'method' => 'TUNAI',
    ])->assertSessionHasErrors(['paid_on' => $locked]);
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $this->smpCash, 'entry_date' => '2026-11-28', 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id'),
        'amount' => '10000', 'description' => 'Susulan BOS',
    ])->assertSessionHasErrors(['entry_date' => $locked]);
    $this->post(route('finance.savings.deposit'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '10000', 'entry_date' => '2026-11-28', 'method' => 'TUNAI',
    ])->assertSessionHasErrors(['entry_date' => $locked]);
    $this->post(route('finance.cash-transfers.store'), [
        'account_id' => $this->smpCash, 'to_account_id' => $this->yayasanCash, 'entry_date' => '2026-11-28', 'amount' => '10000', 'description' => 'Setoran',
    ])->assertSessionHasErrors(['entry_date' => $locked]);
    $this->post(route('finance.journals.store'), [
        'date' => '2026-11-28', 'description' => 'Koreksi',
        'lines' => [
            ['account_id' => fnplAccount('5-1700'), 'organization_id' => '', 'debit' => '1000', 'credit' => ''],
            ['account_id' => fnplAccount('1-2900'), 'organization_id' => '', 'debit' => '', 'credit' => '1000'],
        ],
    ])->assertSessionHasErrors(['date' => $locked]);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->spp->id, 'from' => '2026-11', 'to' => '2027-01',
    ])->assertSessionHasErrors(['from' => 'November 2026 sudah ditutup buku (terkunci sampai 30 November 2026). Mulai dari Desember 2026, atau minta pengelola pembukuan Yayasan membuka kuncinya.']);
    $this->post(route('finance.opening-items.store'), [
        'lingkup' => 'yayasan', 'account_id' => fnplAccount('1-2100'), 'side' => 'DEBIT', 'amount' => '1000', 'note' => 'Piutang lain',
    ])->assertSessionHasErrors('form');
    expect(fn () => app(ManageExpenses::class)->realize($this->tenant->id, $this->expense, $this->smpCash, '2026-11-30', '30000', null, null))
        ->toThrow(FinanceException::class);

    // Pembatalan transaksi November ditolak.
    $voided = 'di periode yang sudah ditutup buku (terkunci sampai 30 November 2026)';
    $payment = (string) DB::table('fn_payments')->value('id');
    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Salah input'])
        ->assertSessionHasErrors(['form' => 'Transaksi ini bertanggal 10 November 2026, di periode yang sudah ditutup buku (terkunci sampai 30 November 2026). Minta pengelola pembukuan Yayasan membuka kuncinya dulu.']);
    $income = (string) DB::table('fn_cash_entries')->where('source', 'MANUAL')->value('id');
    $this->post(route('finance.cash-entries.void', ['entry' => $income]), ['reason' => 'Salah'])->assertSessionHasErrors('form');
    $this->post(route('finance.cash-transfers.void', ['transfer' => (string) DB::table('fn_cash_transfers')->value('id')]), ['reason' => 'Salah'])->assertSessionHasErrors('form');
    $this->post(route('finance.savings.void', ['entry' => (string) DB::table('fn_savings_entries')->value('id')]), ['reason' => 'Salah'])->assertSessionHasErrors('form');
    $this->post(route('finance.invoices.void', ['invoice' => (string) DB::table('fn_invoices')->where('period', '2026-11-01')->value('id')]), ['reason' => 'Salah'])->assertSessionHasErrors('form');
    $this->post(route('finance.journals.void', ['journal' => (string) DB::table('fn_journals')->where('source', 'MANUAL')->value('id')]), ['reason' => 'Salah'])->assertSessionHasErrors('form');
    expect(fn () => app(ManageExpenses::class)->voidRealization($this->tenant->id, $this->expense, 'Salah', null))
        ->toThrow(FinanceException::class, $voided);

    expect(DB::table('fn_payments')->where('status', 'BATAL')->count())->toBe(0)
        ->and(DB::table('fn_cash_entries')->where('status', 'BATAL')->count())->toBe(0)
        ->and(DB::table('fn_invoices')->where('status', 'BATAL')->count())->toBe(0);

    // Desember tetap jalan: bayar tagihan Oktober hari ini, batal tagihan Desember, jurnal manual Desember.
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->enrollment, 'amount' => '150000', 'paid_on' => '2026-12-05', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    expect(DB::table('fn_invoices')->where('period', '2026-10-01')->value('status'))->toBe('LUNAS');
    $this->post(route('finance.invoices.void', ['invoice' => (string) DB::table('fn_invoices')->where('period', '2026-12-01')->value('id')]), ['reason' => 'Beasiswa penuh'])
        ->assertSessionHasNoErrors();
    $this->post(route('finance.journals.store'), [
        'date' => '2026-12-01', 'description' => 'Penyusutan Desember',
        'lines' => [
            ['account_id' => fnplAccount('5-1700'), 'organization_id' => '', 'debit' => '50000', 'credit' => ''],
            ['account_id' => fnplAccount('1-2900'), 'organization_id' => '', 'debit' => '', 'credit' => '50000'],
        ],
    ])->assertSessionHasNoErrors();

    $this->artisan('finance:ledger-process')->assertSuccessful();
    expect(DB::table('fn_ledger_queue')->count())->toBe(0);
});

test('pengaman database: perubahan langsung ke periode terkunci ditolak; susun ulang tidak menyentuh jurnal terkunci', function () {
    fnplLock($this, '2026-11-30');

    expect(fn () => DB::transaction(fn () => DB::table('fn_cash_entries')->where('source', 'MANUAL')->update(['amount' => 1])))->toThrow(QueryException::class, LedgerLock::DATABASE_MARKER)
        ->and(fn () => DB::transaction(fn () => DB::table('fn_invoices')->where('period', '2026-11-01')->update(['amount' => 1])))->toThrow(QueryException::class, LedgerLock::DATABASE_MARKER)
        ->and(fn () => DB::transaction(fn () => DB::table('fn_journals')->where('source', 'MANUAL')->update(['description' => 'Diubah'])))->toThrow(QueryException::class, LedgerLock::DATABASE_MARKER)
        ->and(fn () => DB::transaction(fn () => DB::table('fn_journal_lines')->whereIn('journal_id', DB::table('fn_journals')->where('source', 'MANUAL')->select('id'))->delete()))->toThrow(QueryException::class, LedgerLock::DATABASE_MARKER)
        ->and(fn () => DB::transaction(fn () => DB::table('fn_ledger_settings')->update(['start_date' => '2026-11-01'])))->toThrow(QueryException::class, LedgerLock::DATABASE_MARKER);

    // Pembayaran tagihan lama (sisa bayar berubah) bukan perubahan jurnal: tetap boleh.
    DB::table('fn_invoices')->where('period', '2026-11-01')->update(['paid_amount' => 0, 'updated_at' => now()]);

    // Pesan ramah untuk penolakan database.
    try {
        DB::transaction(fn () => DB::table('fn_cash_transfers')->update(['description' => 'x']));
    } catch (QueryException $exception) {
        expect(LedgerLock::messageFor($exception))->toBe('Perubahan ini menyentuh periode yang sudah ditutup buku (terkunci sampai 30 November 2026). Minta pengelola pembukuan Yayasan membuka kuncinya dulu.');
    }

    // Susun ulang paksa sesudah ganti pemetaan: jurnal November dibekukan, Desember ikut pemetaan baru.
    $before = DB::table('fn_journals')->where('journal_date', '<=', '2026-11-30')->orderBy('id')->get(['id', 'number', 'updated_at'])->map(fn ($row) => (array) $row)->all();
    $bos = (string) DB::table('fn_cash_categories')->where('code', 'BOS')->value('id');
    $this->post(route('finance.ledger-mappings.set'), ['kind' => 'kategori', 'item_id' => $bos, 'account_id' => fnplAccount('4-3100')])->assertSessionHasNoErrors();

    // Kunci berikutnya menunggu susun ulang selesai.
    $this->travelTo(CarbonImmutable::parse('2027-01-05 09:00:00', BusinessClock::timezone()));
    $this->post(route('finance.ledger-lock.lock'), ['locked_through' => '2026-12-31'])->assertSessionHasErrors('locked_through');

    $this->post(route('finance.ledger-rebuild'))->assertSessionHasNoErrors();

    for ($i = 0; $i < 20 && DB::table('fn_ledger_settings')->whereNotNull('rebuild_version')->exists(); $i++) {
        $this->post(route('finance.ledger-rebuild'))->assertSessionHasNoErrors();
    }

    $after = DB::table('fn_journals')->where('journal_date', '<=', '2026-11-30')->orderBy('id')->get(['id', 'number', 'updated_at'])->map(fn ($row) => (array) $row)->all();
    expect($after)->toBe($before);

    fnplLock($this, '2026-12-31');
    expect(DB::table('fn_ledger_settings')->value('locked_through'))->toBe('2026-12-31');
});

test('buka kunci: alasan wajib, mundur ke Oktober → transaksi November bisa dibatalkan; buka semua; audit; hak akses', function () {
    fnplLock($this, '2026-11-30');

    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '2026-10-31', 'reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Tulis alasan membuka kunci (5–200 karakter).']);
    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '2026-11-30', 'reason' => 'Koreksi kuitansi'])
        ->assertSessionHasErrors(['unlocked_through' => 'Pilih bulan sebelum November 2026, atau buka semua.']);

    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '2026-10-31', 'reason' => 'Koreksi kuitansi salah tanggal'])->assertSessionHasNoErrors();
    expect(DB::table('fn_ledger_settings')->value('locked_through'))->toBe('2026-10-31');

    $payment = (string) DB::table('fn_payments')->value('id');
    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Salah tanggal'])->assertSessionHasNoErrors();
    expect(DB::table('fn_payments')->where('id', $payment)->value('status'))->toBe('BATAL');

    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '', 'reason' => 'Audit ulang tahun buku'])->assertSessionHasNoErrors();
    expect(DB::table('fn_ledger_settings')->value('locked_through'))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::PERIOD_UNLOCKED)->count())->toBe(2);

    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '', 'reason' => 'Lagi'])->assertSessionHasErrors('reason');
    $this->post(route('finance.ledger-lock.unlock'), ['unlocked_through' => '', 'reason' => 'Buka lagi'])
        ->assertSessionHasErrors(['unlocked_through' => 'Belum ada periode yang terkunci.']);

    // Bendahara lembaga tanpa izin kelola pembukuan tingkat Yayasan.
    $this->flushSession();
    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->actingAs($bendahara)
        ->post(route('finance.ledger-lock.lock'), ['locked_through' => '2026-11-30'])
        ->assertForbidden();
});
