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
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kas umum KS-1 (PRD-004 §3–§6). "Hari ini" Jumat 30 Okt 2026.
 * Kas SMP Unit 3: Kas tunai saldo awal Rp 1.000.000 per 1 Sep; rekening BSI saldo awal Rp 5.000.000 per 1 Okt.
 *   20 Sep  Donasi  Rp   300.000  → Kas tunai
 *   15 Okt  BOS     Rp 2.500.000  → BSI
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
});

function fnkCategory(string $code): string
{
    return (string) DB::table('fn_cash_categories')->where('code', $code)->value('id');
}

function fnkAccount(string $organizationId, string $name): string
{
    return (string) DB::table('fn_cash_accounts')->where('organization_id', $organizationId)->where('name', $name)->value('id');
}

/**
 * Siapkan kas SMP sesuai skenario di atas.
 */
function fnkSetup(mixed $test): void
{
    $test->get(route('finance.cash.index', ['kas' => $test->smp->id]))->assertOk();

    $test->patch(route('finance.cash-accounts.update', ['account' => fnkAccount($test->smp->id, 'Kas tunai')]), [
        'opening_balance' => '1.000.000', 'opening_date' => '2026-09-01',
    ])->assertSessionHasNoErrors();
    $test->post(route('finance.cash-accounts.store'), [
        'kas' => $test->smp->id, 'name' => 'BSI operasional', 'bank_name' => 'Bank Syariah Indonesia', 'account_number' => '7123-456-789',
        'opening_balance' => '5000000', 'opening_date' => '2026-10-01',
    ])->assertSessionHasNoErrors();

    foreach ([['Kas tunai', '2026-09-20', 'DONASI', '300000', 'Infak wali murid'], ['BSI operasional', '2026-10-15', 'BOS', '2.500.000', 'Dana BOS tahap 2']] as [$account, $date, $category, $amount, $description]) {
        $test->post(route('finance.cash-income.store'), [
            'kas' => $test->smp->id, 'account_id' => fnkAccount($test->smp->id, $account), 'entry_date' => $date,
            'category_id' => fnkCategory($category), 'amount' => $amount, 'description' => $description,
        ])->assertSessionHasNoErrors();
    }
}

test('kas: kategori bawaan & Kas tunai otomatis; rekening, saldo awal, pemasukan, buku kas bulanan dengan saldo berjalan', function () {
    $this->get(route('finance.cash.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cash')
            ->where('books.0', ['value' => 'yayasan', 'label' => 'Kas Yayasan'])
            ->where('bookKey', 'yayasan')
            ->where('canManage', true)
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Kas tunai')
            ->where('incomeCategories', [
                ['value' => fnkCategory('BOS'), 'label' => 'Dana BOS'],
                ['value' => fnkCategory('DONASI'), 'label' => 'Donasi / infak'],
                ['value' => fnkCategory('MASUK-LAIN'), 'label' => 'Pemasukan lain-lain'],
            ]));

    expect(DB::table('fn_cash_categories')->count())->toBe(10)
        ->and(DB::table('fn_cash_accounts')->whereNull('organization_id')->where('kind', 'TUNAI')->count())->toBe(1);

    fnkSetup($this);

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id, 'bulan' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookKey', $this->smp->id)
            ->has('accounts', 2)
            ->where('accounts.0.name', 'Kas tunai')
            ->where('accounts.0.balance', 1300000)
            ->where('accounts.0.has_entries', true)
            ->where('accounts.1.name', 'BSI operasional')
            ->where('accounts.1.bank_name', 'Bank Syariah Indonesia')
            ->where('accounts.1.account_number', '7123-456-789')
            ->where('accounts.1.balance', 7500000)
            ->where('ledger.opening', 1300000)
            ->where('ledger.total_in', 2500000)
            ->where('ledger.total_out', 0)
            ->where('ledger.closing', 8800000)
            ->has('ledger.rows', 2)
            ->where('ledger.rows.0.type', 'opening')
            ->where('ledger.rows.0.description', 'Saldo awal BSI operasional')
            ->where('ledger.rows.0.balance', 6300000)
            ->where('ledger.rows.1.description', 'Dana BOS tahap 2')
            ->where('ledger.rows.1.category', 'Dana BOS')
            ->where('ledger.rows.1.recorded_by', 'Admin Yayasan')
            ->where('ledger.rows.1.balance', 8800000));

    // Satu rekening, bulan September.
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id, 'bulan' => '2026-09', 'rekening' => fnkAccount($this->smp->id, 'Kas tunai')]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ledger.opening', 0)
            ->where('ledger.rows.0.description', 'Saldo awal Kas tunai')
            ->where('ledger.rows.1.amount', 300000)
            ->where('ledger.closing', 1300000));

    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::CASH_ENTRY_RECORDED)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::CASH_ACCOUNT_CREATED)->count())->toBe(1);
});

test('ditolak: tanggal salah, kategori sistem/pengeluaran, uraian & jumlah tidak sah; saldo awal terkunci setelah ada transaksi', function () {
    fnkSetup($this);
    $bsi = fnkAccount($this->smp->id, 'BSI operasional');
    $income = fn (array $overrides) => array_merge([
        'kas' => $this->smp->id, 'account_id' => $bsi, 'entry_date' => '2026-10-20', 'category_id' => fnkCategory('BOS'), 'amount' => '1000', 'description' => 'Uji coba',
    ], $overrides);

    $this->post(route('finance.cash-income.store'), $income(['entry_date' => '2026-10-31']))->assertSessionHasErrors('entry_date');
    $this->post(route('finance.cash-income.store'), $income(['entry_date' => '2026-09-30']))
        ->assertSessionHasErrors(['entry_date' => 'Tanggal tidak boleh sebelum tanggal saldo awal rekening (2026-10-01).']);
    $this->post(route('finance.cash-income.store'), $income(['category_id' => fnkCategory('PEMBAYARAN-SISWA')]))->assertSessionHasErrors('category_id');
    $this->post(route('finance.cash-income.store'), $income(['category_id' => fnkCategory('ATK')]))->assertSessionHasErrors('category_id');
    $this->post(route('finance.cash-income.store'), $income(['description' => 'ab']))->assertSessionHasErrors('description');
    $this->post(route('finance.cash-income.store'), $income(['amount' => '0']))->assertSessionHasErrors('amount');
    // Rekening kas lain (MTs) tidak bisa dipakai dari kas SMP.
    $this->get(route('finance.cash.index', ['kas' => $this->mts->id]))->assertOk();
    $this->post(route('finance.cash-income.store'), $income(['account_id' => fnkAccount($this->mts->id, 'Kas tunai')]))->assertSessionHasErrors('account_id');

    $this->patch(route('finance.cash-accounts.update', ['account' => $bsi]), [
        'name' => 'BSI', 'bank_name' => 'BSI', 'account_number' => '', 'opening_balance' => '1', 'opening_date' => '2026-10-01',
    ])->assertSessionHasErrors(['opening_balance' => 'Saldo awal tidak bisa diubah karena rekening ini sudah punya transaksi.']);
    // Nama boleh diubah walau sudah ada transaksi.
    $this->patch(route('finance.cash-accounts.update', ['account' => $bsi]), [
        'name' => 'BSI', 'bank_name' => 'BSI', 'account_number' => '', 'opening_balance' => '5000000', 'opening_date' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-accounts.store'), [
        'kas' => $this->smp->id, 'name' => 'X', 'bank_name' => '', 'opening_balance' => '0', 'opening_date' => '2026-10-01',
    ])->assertSessionHasErrors('bank_name');

    expect(DB::table('fn_cash_entries')->count())->toBe(2)
        ->and(DB::table('fn_cash_accounts')->where('id', $bsi)->value('name'))->toBe('BSI');
});

test('batal transaksi: tetap tampil dicoret & tidak dihitung; tidak bisa dua kali; ditolak bila saldo jadi minus', function () {
    fnkSetup($this);
    $bsi = fnkAccount($this->smp->id, 'BSI operasional');
    $bos = (string) DB::table('fn_cash_entries')->where('description', 'Dana BOS tahap 2')->value('id');

    $this->post(route('finance.cash-entries.void', ['entry' => $bos]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('finance.cash-entries.void', ['entry' => $bos]), ['reason' => 'Salah rekening'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-entries.void', ['entry' => $bos]), ['reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Transaksi ini sudah dibatalkan.']);

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id, 'rekening' => $bsi]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.1.balance', 5000000)
            ->where('ledger.total_in', 0)
            ->where('ledger.rows.1.status', 'BATAL')
            ->where('ledger.rows.1.void_reason', 'Salah rekening')
            ->where('ledger.rows.1.balance', null));

    // Pemasukan yang uangnya sudah terpakai (pengeluaran KS-3, disimulasikan) tidak bisa dibatalkan.
    $this->post(route('finance.cash-income.store'), [
        'kas' => $this->smp->id, 'account_id' => $bsi, 'entry_date' => '2026-10-20', 'category_id' => fnkCategory('DONASI'), 'amount' => '100000', 'description' => 'Donasi alumni',
    ])->assertSessionHasNoErrors();
    DB::table('fn_cash_entries')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp->id, 'account_id' => $bsi,
        'category_id' => fnkCategory('ATK'), 'entry_date' => '2026-10-21', 'direction' => 'KELUAR', 'amount' => 5050000,
        'description' => 'Beli laptop', 'source' => 'PENGELUARAN', 'status' => 'SAH', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $donation = (string) DB::table('fn_cash_entries')->where('description', 'Donasi alumni')->value('id');
    $laptop = (string) DB::table('fn_cash_entries')->where('description', 'Beli laptop')->value('id');

    $this->post(route('finance.cash-entries.void', ['entry' => $donation]), ['reason' => 'Salah'])
        ->assertSessionHasErrors(['form' => 'Pembatalan ditolak: saldo BSI operasional akan menjadi minus.']);
    $this->post(route('finance.cash-entries.void', ['entry' => $laptop]), ['reason' => 'Salah'])
        ->assertSessionHasErrors(['form' => 'Transaksi ini berasal dari proses lain dan dibatalkan dari asalnya.']);

    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::CASH_ENTRY_VOIDED)->count())->toBe(1);
});

test('kategori kas: tambah, kode unik, ubah nama, nonaktif/aktif; kategori sistem terkunci; hanya tingkat Yayasan', function () {
    $this->get(route('finance.cash-categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/cash-categories')->has('categories', 10)->where('categories.0.code', 'PEMBAYARAN-SISWA'));

    $this->post(route('finance.cash-categories.store'), ['code' => 'sewa', 'name' => 'Sewa gedung', 'direction' => 'KELUAR'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-categories.store'), ['code' => 'SEWA', 'name' => 'Lagi', 'direction' => 'KELUAR'])
        ->assertSessionHasErrors(['code' => 'Kode SEWA sudah dipakai kategori lain.']);
    $this->post(route('finance.cash-categories.store'), ['code' => 'X1', 'name' => 'Salah', 'direction' => 'LAIN'])->assertSessionHasErrors('direction');

    $sewa = fnkCategory('SEWA');
    $this->patch(route('finance.cash-categories.update', ['category' => $sewa]), ['name' => 'Sewa tempat'])->assertSessionHasNoErrors();
    $this->post(route('finance.cash-categories.deactivate', ['category' => fnkCategory('BOS')]))->assertSessionHasNoErrors();
    $this->post(route('finance.cash-categories.deactivate', ['category' => fnkCategory('PEMBAYARAN-SISWA')]))
        ->assertSessionHasErrors(['form' => 'Kategori sistem tidak bisa diubah.']);

    expect(DB::table('fn_cash_categories')->where('id', $sewa)->value('name'))->toBe('Sewa tempat')
        ->and((bool) DB::table('fn_cash_categories')->where('code', 'BOS')->value('is_active'))->toBeFalse();

    // Kategori nonaktif tidak bisa dipilih.
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertInertia(fn (Assert $page) => $page->has('incomeCategories', 2));

    $this->post(route('finance.cash-categories.activate', ['category' => fnkCategory('BOS')]))->assertSessionHasNoErrors();

    CoreFixtures::syncAccess();
    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->flushSession();
    $this->actingAs($bendahara)->get(route('finance.cash-categories.index'))->assertForbidden();
    $this->actingAs($bendahara)->post(route('finance.cash-categories.store'), ['code' => 'ZZ', 'name' => 'Z', 'direction' => 'MASUK'])->assertForbidden();
});

test('hak akses: Bendahara hanya kas lembaganya; Kepala Lembaga hanya melihat; tanpa izin kas 403', function () {
    fnkSetup($this);
    $this->get(route('finance.cash.index', ['kas' => $this->mts->id]))->assertOk();
    $mtsCash = fnkAccount($this->mts->id, 'Kas tunai');
    $smpBos = (string) DB::table('fn_cash_entries')->where('description', 'Dana BOS tahap 2')->value('id');

    CoreFixtures::syncAccess();
    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');

    $this->flushSession();
    $this->actingAs($bendahara)->get(route('finance.cash.index', ['kas' => 'yayasan']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('books', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']])
            ->where('bookKey', $this->smp->id)
            ->where('canManage', true)
            ->where('accounts.0.balance', 1300000));
    $this->actingAs($bendahara)->post(route('finance.cash-accounts.store'), [
        'kas' => 'yayasan', 'name' => 'X', 'bank_name' => 'X', 'opening_balance' => '0', 'opening_date' => '2026-10-01',
    ])->assertNotFound();
    $this->actingAs($bendahara)->post(route('finance.cash-income.store'), [
        'kas' => $this->mts->id, 'account_id' => $mtsCash, 'entry_date' => '2026-10-20', 'category_id' => fnkCategory('BOS'), 'amount' => '1000', 'description' => 'Uji coba',
    ])->assertNotFound();
    $this->actingAs($bendahara)->patch(route('finance.cash-accounts.update', ['account' => $mtsCash]), ['opening_balance' => '1', 'opening_date' => '2026-10-01'])->assertNotFound();

    $this->flushSession();
    $this->actingAs($kepala)->get(route('finance.cash.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('bookKey', $this->smp->id)->where('canManage', false));
    $this->actingAs($kepala)->post(route('finance.cash-entries.void', ['entry' => $smpBos]), ['reason' => 'X'])->assertForbidden();

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.cash.index'))->assertForbidden();

    expect(DB::table('fn_cash_entries')->where('status', 'SAH')->count())->toBe(2);
});
