<?php

use Carbon\CarbonImmutable;
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
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pembayaran siswa masuk buku kas (PRD-004 KS-2). "Hari ini" Jumat 30 Okt 2026, SMP Unit 3, SPP Rp 150.000 Juli–Oktober.
 *   U3-SMP/2026/000001  05 Okt  Tunai     Rp 150.000  (dicatat sebelum kas pernah dibuka)
 *   U3-SMP/2026/000002  30 Okt  Transfer  Rp 150.000
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin);

    $this->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-10',
    ])->assertSessionHasNoErrors();

    $enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');

    foreach ([['2026-10-05', 'TUNAI'], ['2026-10-30', 'TRANSFER']] as [$date, $method]) {
        $this->post(route('finance.payments.store'), [
            'organization_id' => $this->smp->id, 'enrollment_id' => $enrollment, 'amount' => '150000', 'paid_on' => $date, 'method' => $method,
        ])->assertSessionHasNoErrors();
    }
});

function fnmPayment(string $number): string
{
    return (string) DB::table('fn_payments')->where('receipt_number', $number)->value('id');
}

function fnmAccount(string $organizationId, string $name): string
{
    return (string) DB::table('fn_cash_accounts')->where('organization_id', $organizationId)->where('name', $name)->value('id');
}

test('kuitansi kasir masuk buku kas sejak tanggal saldo awal; transfer ke rekening penerima; disusun ulang saat saldo awal/penerima berubah', function () {
    // Kas tunai dibuat otomatis saat kuitansi pertama (saldo awal per hari ini): kuitansi 5 Okt sudah "termasuk saldo awal".
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receivesPayments', true)
            ->has('accounts', 1)
            ->where('accounts.0.balance', 150000)
            ->where('accounts.0.has_entries', false)
            ->has('ledger.rows', 2)
            ->where('ledger.rows.1.description', 'Pembayaran siswa — Ahmad Fauzi (U3-SMP/2026/000002)')
            ->where('ledger.rows.1.category', 'Pembayaran siswa')
            ->where('ledger.rows.1.source', 'PEMBAYARAN')
            ->where('ledger.rows.1.source_id', fnmPayment('U3-SMP/2026/000002'))
            ->where('ledger.rows.1.recorded_by', 'Admin Yayasan'));

    // Saldo awal Kas tunai dimundurkan → kuitansi 5 Okt ikut masuk (cerminan tidak mengunci saldo awal).
    $cash = fnmAccount($this->smp->id, 'Kas tunai');
    $this->patch(route('finance.cash-accounts.update', ['account' => $cash]), ['opening_balance' => '0', 'opening_date' => '2026-09-01'])->assertSessionHasNoErrors();

    expect(DB::table('fn_cash_entries')->where('account_id', $cash)->where('source', 'PEMBAYARAN')->orderBy('entry_date')->pluck('entry_date')->map(fn ($date) => substr((string) $date, 0, 10))->all())
        ->toBe(['2026-10-05', '2026-10-30']);

    // Rekening bank penerima transfer → kuitansi transfer pindah ke sana.
    $this->post(route('finance.cash-accounts.store'), [
        'kas' => $this->smp->id, 'name' => 'BSI SPP', 'bank_name' => 'BSI', 'opening_balance' => '0', 'opening_date' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    $bsi = fnmAccount($this->smp->id, 'BSI SPP');
    $this->post(route('finance.cash-accounts.transfer-receiver', ['account' => $bsi]), ['receives' => true])->assertSessionHasNoErrors();

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.balance', 150000)
            ->where('accounts.1.name', 'BSI SPP')
            ->where('accounts.1.receives_transfers', true)
            ->where('accounts.1.balance', 150000)
            ->where('accounts.1.has_entries', false));

    // Kuitansi baru langsung masuk (tanpa membuka halaman kas).
    $enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $enrollment, 'amount' => '100000', 'paid_on' => '2026-10-30', 'method' => 'TRANSFER',
    ])->assertSessionHasNoErrors();

    expect(DB::table('fn_cash_entries')->where('account_id', $bsi)->where('status', 'SAH')->sum('amount'))->toEqual(250000)
        ->and(DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->count())->toBe(3);

    // Lepas penerima → transfer kembali ke Kas tunai.
    $this->post(route('finance.cash-accounts.transfer-receiver', ['account' => $bsi]), ['receives' => false])->assertSessionHasNoErrors();
    expect(DB::table('fn_cash_entries')->where('account_id', $cash)->where('status', 'SAH')->sum('amount'))->toEqual(400000)
        ->and(DB::table('fn_cash_entries')->where('account_id', $bsi)->count())->toBe(0);
});

test('kuitansi dibatalkan → baris kas ikut batal; ditolak bila saldo rekening menjadi minus', function () {
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();
    $cash = fnmAccount($this->smp->id, 'Kas tunai');

    $this->post(route('finance.payments.void', ['payment' => fnmPayment('U3-SMP/2026/000002')]), ['reason' => 'Transfer belum masuk'])->assertSessionHasNoErrors();

    $mirror = DB::table('fn_cash_entries')->where('source_id', fnmPayment('U3-SMP/2026/000002'))->sole();
    expect($mirror->status)->toBe('BATAL')
        ->and($mirror->void_reason)->toBe('Pembayaran dibatalkan: Transfer belum masuk');

    // Kuitansi baru 30 Okt Rp 150.000, lalu uangnya "terpakai" (pengeluaran KS-3, disimulasikan) → pembatalan ditolak.
    $enrollment = (string) DB::table('ac_enrollments')->where('organization_id', $this->smp->id)->value('id');
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $enrollment, 'amount' => '150000', 'paid_on' => '2026-10-30', 'method' => 'TUNAI',
    ])->assertSessionHasNoErrors();
    DB::table('fn_cash_entries')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp->id, 'account_id' => $cash,
        'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'ATK')->value('id'), 'entry_date' => '2026-10-30', 'direction' => 'KELUAR',
        'amount' => 100000, 'description' => 'Beli kertas', 'source' => 'PENGELUARAN', 'status' => 'SAH', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->post(route('finance.payments.void', ['payment' => fnmPayment('U3-SMP/2026/000003')]), ['reason' => 'Salah'])
        ->assertSessionHasErrors(['form' => 'Pembatalan ditolak: saldo Kas tunai di buku kas akan menjadi minus (uangnya sudah dipakai). Hubungi bendahara.']);

    expect(DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000003')->value('status'))->toBe('SAH')
        ->and(DB::table('fn_invoices')->where('status', 'LUNAS')->count())->toBe(2);
});

test('penerima transfer: hanya rekening bank lembaga; Kepala Lembaga tidak bisa mengubah', function () {
    $this->get(route('finance.cash.index', ['kas' => 'yayasan']))
        ->assertInertia(fn (Assert $page) => $page->where('receivesPayments', false));
    $this->post(route('finance.cash-accounts.store'), [
        'kas' => 'yayasan', 'name' => 'BSI Yayasan', 'bank_name' => 'BSI', 'opening_balance' => '0', 'opening_date' => '2026-10-01',
    ])->assertSessionHasNoErrors();
    $yayasanBank = (string) DB::table('fn_cash_accounts')->where('name', 'BSI Yayasan')->value('id');

    $this->post(route('finance.cash-accounts.transfer-receiver', ['account' => $yayasanBank]), ['receives' => true])
        ->assertSessionHasErrors(['form' => 'Penerima transfer siswa harus rekening bank aktif milik lembaga.']);
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();
    $this->post(route('finance.cash-accounts.transfer-receiver', ['account' => fnmAccount($this->smp->id, 'Kas tunai')]), ['receives' => true])
        ->assertSessionHasErrors('form');

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->flushSession();
    $this->actingAs($kepala)->post(route('finance.cash-accounts.transfer-receiver', ['account' => fnmAccount($this->smp->id, 'Kas tunai')]), ['receives' => true])->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.cash-accounts.transfer-receiver', ['account' => $yayasanBank]), ['receives' => true])->assertNotFound();
});
