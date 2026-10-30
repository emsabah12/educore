<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
 * Pengeluaran KS-3 (PRD-004 §4). "Hari ini" Jumat 30 Okt 2026. Kas SMP Unit 3: Kas tunai saldo awal Rp 1.000.000 per 1 Okt.
 * Bendahara SMP mengajukan, Kepala SMP menyetujui, Bendahara merealisasi.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));
    Storage::fake('local');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');

    $this->actingAs($this->admin)->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();
    $this->cash = (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->value('id');
    $this->patch(route('finance.cash-accounts.update', ['account' => $this->cash]), ['opening_balance' => '1000000', 'opening_date' => '2026-10-01'])->assertSessionHasNoErrors();

    CoreFixtures::syncAccess();
    $this->bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($this->bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($this->kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->flushSession();
});

function fneCategory(string $code): string
{
    return (string) DB::table('fn_cash_categories')->where('code', $code)->value('id');
}

function fneExpense(string $description): object
{
    return DB::table('fn_expense_requests')->where('description', $description)->sole();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fneRequest(mixed $test, User $user, string $description, string $amount, array $overrides = []): mixed
{
    $test->flushSession();

    return $test->actingAs($user)->post(route('finance.expenses.store'), array_merge([
        'kas' => $test->smp->id, 'category_id' => fneCategory('ATK'), 'description' => $description, 'amount' => $amount,
    ], $overrides));
}

function fneBalance(string $accountId): int
{
    $opening = (int) DB::table('fn_cash_accounts')->where('id', $accountId)->value('opening_balance');
    $in = (int) DB::table('fn_cash_entries')->where('account_id', $accountId)->where('status', 'SAH')->where('direction', 'MASUK')->sum('amount');
    $out = (int) DB::table('fn_cash_entries')->where('account_id', $accountId)->where('status', 'SAH')->where('direction', 'KELUAR')->sum('amount');

    return $opening + $in - $out;
}

test('alur lengkap: ajukan (dengan lampiran) → setujui sebagian → realisasi dengan bukti → saldo berkurang; kartu Beranda', function () {
    fneRequest($this, $this->bendahara, 'Kertas HVS 10 rim', '450.000', [
        'needed_on' => '2026-11-02', 'attachment' => UploadedFile::fake()->create('penawaran toko.pdf', 120, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $expense = fneExpense('Kertas HVS 10 rim');

    expect($expense->status)->toBe('DIAJUKAN')
        ->and($expense->attachment_name)->toBe('penawaran toko.pdf');
    Storage::disk('local')->assertExists($expense->attachment_path);

    // Bendahara melihat pengajuannya, tidak bisa memutuskan (tanpa izin) — kartu "siap direalisasi" 0.
    $this->get(route('finance.expenses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/expenses')
            ->where('manageBooks', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']])
            ->where('expenses.rows.0.is_own', true)
            ->where('expenses.rows.0.can_cancel', true)
            ->where('expenses.rows.0.can_decide', false)
            ->where('expenses.rows.0.book', 'SMP Unit 3')
            ->where('expenses.rows.0.requested_by', $this->bendahara->person->name));
    $this->post(route('finance.expenses.approve', ['expense' => $expense->id]), ['approved_amount' => '450000'])->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->kepala)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.expenses_pending' && $card['value'] === 1)));
    $this->get(route('finance.expenses.index', ['status' => 'DIAJUKAN']))
        ->assertInertia(fn (Assert $page) => $page->where('expenses.rows.0.can_decide', true)->where('expenses.rows.0.can_cancel', false)->where('manageBooks', []));
    $this->get(route('finance.expenses.file', ['expense' => $expense->id, 'kind' => 'lampiran']))->assertOk()->assertDownload('penawaran toko.pdf');

    $this->post(route('finance.expenses.approve', ['expense' => $expense->id]), ['approved_amount' => '500000'])->assertSessionHasErrors('approved_amount');
    $this->post(route('finance.expenses.approve', ['expense' => $expense->id]), ['approved_amount' => '400.000', 'decision_note' => 'Cukup 9 rim'])->assertSessionHasNoErrors();
    $this->post(route('finance.expenses.reject', ['expense' => $expense->id]), ['decision_note' => 'X'])
        ->assertSessionHasErrors(['form' => 'Pengajuan ini sudah diputus atau dibatalkan.']);

    // Realisasi oleh bendahara.
    $this->flushSession();
    $this->actingAs($this->bendahara)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.expenses_approved' && $card['value'] === 1 && $card['tone'] === 'warning')));
    $realize = fn (array $overrides) => $this->post(route('finance.expenses.realize', ['expense' => $expense->id]), array_merge([
        'account_id' => $this->cash, 'entry_date' => '2026-10-30', 'amount' => '400000',
    ], $overrides));

    $realize(['amount' => '400001'])->assertSessionHasErrors('amount');
    $realize(['entry_date' => '2026-10-29'])->assertSessionHasErrors(['entry_date' => 'Tanggal keluar tidak boleh sebelum tanggal pengajuan (2026-10-30).']);
    $realize(['entry_date' => '2026-10-31'])->assertSessionHasErrors('entry_date');
    $realize(['amount' => '385.000', 'proof' => UploadedFile::fake()->image('nota.jpg')])->assertSessionHasNoErrors();

    $expense = fneExpense('Kertas HVS 10 rim');
    $entry = DB::table('fn_cash_entries')->where('id', $expense->realized_entry_id)->sole();

    expect($expense->status)->toBe('DIREALISASI')
        ->and($expense->approved_amount)->toBe(400000)
        ->and($expense->proof_name)->toBe('nota.jpg')
        ->and($entry->direction)->toBe('KELUAR')
        ->and($entry->amount)->toBe(385000)
        ->and($entry->source)->toBe('PENGELUARAN')
        ->and($entry->description)->toBe('Pengeluaran — Kertas HVS 10 rim')
        ->and($entry->category_id)->toBe(fneCategory('ATK'))
        ->and(fneBalance($this->cash))->toBe(615000);

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('ledger.total_out', 385000)->where('ledger.closing', 615000)->where('accounts.0.has_entries', true));
    $this->get(route('finance.expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('expenses.rows.0.realized', ['account' => 'Kas tunai', 'date' => '2026-10-30', 'amount' => 385000])
            ->where('expenses.rows.0.can_void', true)
            ->where('expenses.rows.0.decision_note', 'Cukup 9 rim'));

    // Transaksi realisasi tidak bisa dibatalkan dari buku kas.
    $this->post(route('finance.cash-entries.void', ['entry' => $entry->id]), ['reason' => 'X'])
        ->assertSessionHasErrors(['form' => 'Transaksi ini berasal dari proses lain dan dibatalkan dari asalnya.']);

    expect(DB::table('audit_logs')->whereIn('action', [FinanceAuditAction::EXPENSE_REQUESTED, FinanceAuditAction::EXPENSE_APPROVED, FinanceAuditAction::EXPENSE_REALIZED])->count())->toBe(3);
});

test('saldo kurang ditolak; batalkan realisasi → kembali Disetujui, saldo pulih, bukti dihapus; realisasi ulang', function () {
    fneRequest($this, $this->bendahara, 'Servis AC', '1.200.000')->assertSessionHasNoErrors();
    $expense = fneExpense('Servis AC');
    $this->flushSession();
    $this->actingAs($this->kepala)->post(route('finance.expenses.approve', ['expense' => $expense->id]), ['approved_amount' => '1200000'])->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($this->bendahara);
    $this->post(route('finance.expenses.realize', ['expense' => $expense->id]), ['account_id' => $this->cash, 'entry_date' => '2026-10-30', 'amount' => '1200000'])
        ->assertSessionHasErrors(['amount' => 'Saldo Kas tunai tidak cukup (Rp 1.000.000).']);
    $this->post(route('finance.expenses.realize', ['expense' => $expense->id]), [
        'account_id' => $this->cash, 'entry_date' => '2026-10-30', 'amount' => '900000', 'proof' => UploadedFile::fake()->create('nota.pdf', 50, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $proof = fneExpense('Servis AC')->proof_path;

    $this->post(route('finance.expenses.void-realization', ['expense' => $expense->id]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('finance.expenses.void-realization', ['expense' => $expense->id]), ['reason' => 'Salah rekening'])->assertSessionHasNoErrors();

    $expense = fneExpense('Servis AC');
    expect($expense->status)->toBe('DISETUJUI')
        ->and($expense->realized_entry_id)->toBeNull()
        ->and($expense->proof_path)->toBeNull()
        ->and(DB::table('fn_cash_entries')->where('source_id', $expense->id)->value('status'))->toBe('BATAL')
        ->and(fneBalance($this->cash))->toBe(1000000);
    Storage::disk('local')->assertMissing($proof);

    $this->post(route('finance.expenses.realize', ['expense' => $expense->id]), ['account_id' => $this->cash, 'entry_date' => '2026-10-30', 'amount' => '900000'])->assertSessionHasNoErrors();
    expect(fneBalance($this->cash))->toBe(100000)
        ->and(DB::table('fn_cash_entries')->where('source_id', $expense->id)->count())->toBe(2);
});

test('tolak wajib alasan; batalkan hanya selama menunggu; kategori harus pengeluaran; tidak bisa menyetujui pengajuan sendiri', function () {
    fneRequest($this, $this->bendahara, 'Snack rapat', '150000')->assertSessionHasNoErrors();
    fneRequest($this, $this->bendahara, 'Spanduk', '250000')->assertSessionHasNoErrors();
    fneRequest($this, $this->bendahara, 'Salah kategori', '1000', ['category_id' => fneCategory('BOS')])->assertSessionHasErrors('category_id');
    fneRequest($this, $this->bendahara, 'ab', '1000')->assertSessionHasErrors('description');
    fneRequest($this, $this->bendahara, 'Dokumen', '1000', ['attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('attachment');

    $snack = fneExpense('Snack rapat');
    $spanduk = fneExpense('Spanduk');

    $this->post(route('finance.expenses.cancel', ['expense' => $spanduk->id]))->assertSessionHasNoErrors();
    expect(fneExpense('Spanduk')->status)->toBe('DIBATALKAN');

    $this->flushSession();
    $this->actingAs($this->kepala);
    $this->post(route('finance.expenses.reject', ['expense' => $snack->id]), ['decision_note' => ''])->assertSessionHasErrors('decision_note');
    $this->post(route('finance.expenses.reject', ['expense' => $snack->id]), ['decision_note' => 'Pakai dana komite'])->assertSessionHasNoErrors();
    $this->post(route('finance.expenses.approve', ['expense' => $spanduk->id]), ['approved_amount' => '250000'])->assertSessionHasErrors('form');
    $this->post(route('finance.expenses.cancel', ['expense' => $snack->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.expenses.cancel', ['expense' => $snack->id]))
        ->assertSessionHasErrors(['form' => 'Hanya pengajuan yang masih menunggu persetujuan yang bisa dibatalkan.']);

    // Admin Yayasan mengajukan dari kas Yayasan lalu mencoba menyetujui sendiri.
    $this->flushSession();
    $this->actingAs($this->admin)->get(route('finance.cash.index', ['kas' => 'yayasan']))->assertOk();
    $this->post(route('finance.expenses.store'), ['kas' => 'yayasan', 'category_id' => fneCategory('KEGIATAN'), 'description' => 'Rapat yayasan', 'amount' => '500000'])->assertSessionHasNoErrors();
    $own = fneExpense('Rapat yayasan');
    $this->post(route('finance.expenses.approve', ['expense' => $own->id]), ['approved_amount' => '500000'])
        ->assertSessionHasErrors(['form' => 'Anda tidak bisa menyetujui atau menolak pengajuan Anda sendiri.']);
    $this->get(route('finance.expenses.index', ['kas' => 'yayasan']))
        ->assertInertia(fn (Assert $page) => $page->where('expenses.rows.0.is_own', true)->where('expenses.rows.0.can_decide', false));

    expect(fneExpense('Snack rapat')->status)->toBe('DITOLAK')
        ->and(fneExpense('Snack rapat')->decision_note)->toBe('Pakai dana komite');
});

test('hak akses: kas lain 404; lampiran hanya pemilik izin lihat kas; tanpa izin kas 403', function () {
    fneRequest($this, $this->bendahara, 'Kertas', '100000', ['attachment' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $expense = fneExpense('Kertas');

    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($bendaharaMts);
    $this->get(route('finance.expenses.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('expenses.total', 0));
    $this->get(route('finance.expenses.file', ['expense' => $expense->id, 'kind' => 'lampiran']))->assertNotFound();
    $this->post(route('finance.expenses.cancel', ['expense' => $expense->id]))->assertNotFound();
    $this->post(route('finance.expenses.store'), ['kas' => $this->smp->id, 'category_id' => fneCategory('ATK'), 'description' => 'Coba', 'amount' => '1000'])->assertNotFound();
    $this->get(route('finance.expenses.file', ['expense' => $expense->id, 'kind' => 'bukti']))->assertNotFound();

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.expenses.index'))->assertForbidden();
    $this->actingAs($guru)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains(fn (array $card): bool => str_starts_with($card['key'], 'finance.expenses'))));
});
