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
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Application\Payment\Terbilang;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Kasir, kuitansi, batal pembayaran (PRD-003 §4, FN-3). "Hari ini" Jumat 30 Okt 2026.
 * SMP Unit 3, SPP Rp 150.000/bulan, tagihan Juli–Oktober 2026 (jatuh tempo tanggal 10):
 *   Ahmad Fauzi   — aktif, 4 tagihan (Rp 600.000)
 *   Fajar Nugraha — keluar 31 Agt 2026, tagihan Juli & Agustus (Rp 300.000)
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin);

    foreach (['Ahmad Fauzi', 'Fajar Nugraha'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
    }

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-10',
    ])->assertSessionHasNoErrors();

    $fajar = DB::table('ac_enrollments')->where('id', fnpEnrollment('Fajar Nugraha'))->first(['id', 'student_id']);
    $this->post(route('academic.enrollments.close', ['student' => $fajar->student_id, 'enrollment' => $fajar->id]), [
        'end_date' => '2026-08-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();
});

function fnpEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('e.id');
}

function fnpInvoice(string $name, string $month): string
{
    return (string) DB::table('fn_invoices')->where('enrollment_id', fnpEnrollment($name))->where('period', "{$month}-01")->where('status', '!=', 'BATAL')->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnpPay(mixed $test, string $name, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $test->smp->id, 'enrollment_id' => fnpEnrollment($name), 'amount' => '200.000', 'paid_on' => '2026-10-30', 'method' => 'TUNAI',
    ], $overrides);
}

/**
 * @return list<string> "Y-m paid status"
 */
function fnpState(string $name): array
{
    return DB::table('fn_invoices')->where('enrollment_id', fnpEnrollment($name))->where('status', '!=', 'BATAL')->orderBy('period')->get(['period', 'paid_amount', 'status'])
        ->map(fn ($row): string => substr((string) $row->period, 0, 7).' '.$row->paid_amount.' '.$row->status)->all();
}

test('bayar tanpa memilih: terlama dulu, cicil sisa; bayar tagihan terpilih; kuitansi bernomor urut dengan terbilang', function () {
    $response = $this->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi'))->assertSessionHasNoErrors();
    $first = (string) DB::table('fn_payments')->value('id');
    $response->assertRedirect(route('finance.receipts.show', ['payment' => $first]));

    expect(fnpState('Ahmad Fauzi'))->toBe(['2026-07 150000 LUNAS', '2026-08 50000 TERBUKA', '2026-09 0 TERBUKA', '2026-10 0 TERBUKA']);

    $this->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi', ['amount' => '150000', 'method' => 'TRANSFER', 'note' => 'Ref BRI 123', 'invoice_ids' => [fnpInvoice('Ahmad Fauzi', '2026-10')]]))
        ->assertSessionHasNoErrors();

    expect(fnpState('Ahmad Fauzi'))->toBe(['2026-07 150000 LUNAS', '2026-08 50000 TERBUKA', '2026-09 0 TERBUKA', '2026-10 150000 LUNAS'])
        ->and(DB::table('fn_payments')->orderBy('receipt_sequence')->pluck('receipt_number')->all())->toBe(['U3-SMP/2026/000001', 'U3-SMP/2026/000002']);

    $this->get(route('finance.receipts.show', ['payment' => $first]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/receipt')
            ->where('receipt.receipt_number', 'U3-SMP/2026/000001')
            ->where('receipt.student.name', 'Ahmad Fauzi')
            ->where('receipt.amount', 200000)
            ->where('receipt.amount_words', 'dua ratus ribu rupiah')
            ->where('receipt.method', 'Tunai')
            ->where('receipt.recorded_by', 'Admin Yayasan')
            ->where('receipt.items', [['description' => 'SPP — Juli 2026', 'amount' => 150000], ['description' => 'SPP — Agustus 2026', 'amount' => 50000]])
            ->where('backUrl', '/keuangan/kasir?lembaga='.$this->smp->id.'&siswa='.fnpEnrollment('Ahmad Fauzi')));

    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => fnpEnrollment('Ahmad Fauzi')]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/cashier')
            ->where('canPay', true)
            ->where('student.outstanding', 250000)
            ->has('student.invoices', 2)
            ->where('student.invoices.0.outstanding', 100000)
            ->where('student.invoices.0.overdue', true)
            ->has('student.payments', 2)
            ->where('student.payments.0.receipt_number', 'U3-SMP/2026/000002')
            ->where('student.payments.0.items', ['SPP — Oktober 2026']));

    $audit = DB::table('audit_logs')->where('action', FinanceAuditAction::PAYMENT_RECORDED)->count();
    expect($audit)->toBe(2);
});

test('ditolak: kelebihan bayar, tanggal masa depan/terlalu lama, cara bayar salah, tagihan terpilih sudah lunas, tidak ada tagihan', function () {
    $store = route('finance.payments.store');

    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '600001']))
        ->assertSessionHasErrors(['amount' => 'Jumlah bayar melebihi sisa tagihan yang dipilih (Rp 600.000). Kelebihan bayar belum bisa diterima.']);
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '200000', 'invoice_ids' => [fnpInvoice('Ahmad Fauzi', '2026-07')]]))->assertSessionHasErrors('amount');
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['paid_on' => '2026-10-31']))->assertSessionHasErrors('paid_on');
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['paid_on' => '2025-10-29']))->assertSessionHasErrors('paid_on');
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['method' => 'QRIS']))->assertSessionHasErrors('method');
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '0']))->assertSessionHasErrors('amount');

    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '150000', 'invoice_ids' => [fnpInvoice('Ahmad Fauzi', '2026-07')]]))->assertSessionHasNoErrors();
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '1000', 'invoice_ids' => [fnpInvoice('Ahmad Fauzi', '2026-07')]]))
        ->assertSessionHasErrors(['invoice_ids' => 'Sebagian tagihan yang dipilih sudah lunas, batal, atau bukan milik siswa ini. Muat ulang halaman.']);

    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '450000']))->assertSessionHasNoErrors();
    $this->post($store, fnpPay($this, 'Ahmad Fauzi', ['amount' => '1000']))->assertSessionHasErrors(['invoice_ids' => 'Tidak ada tagihan yang belum lunas untuk dibayar.']);

    expect(DB::table('fn_payments')->count())->toBe(2);
});

test('batal pembayaran: tagihan terbuka kembali, kuitansi tetap ada bertanda batal; siswa yang sudah keluar tetap bisa membayar tunggakan', function () {
    $this->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi'))->assertSessionHasNoErrors();
    $payment = (string) DB::table('fn_payments')->value('id');

    // Tagihan yang sudah ada pembayarannya tidak bisa dibatalkan.
    $this->post(route('finance.invoices.void', ['invoice' => fnpInvoice('Ahmad Fauzi', '2026-08')]), ['reason' => 'X'])->assertSessionHasErrors('form');

    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Salah siswa'])->assertSessionHasNoErrors();
    $this->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Pembayaran ini sudah dibatalkan.']);

    expect(fnpState('Ahmad Fauzi'))->toBe(['2026-07 0 TERBUKA', '2026-08 0 TERBUKA', '2026-09 0 TERBUKA', '2026-10 0 TERBUKA'])
        ->and(json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::PAYMENT_VOIDED)->sole()->changes, true))
        ->toEqual(['kuitansi' => 'U3-SMP/2026/000001', 'jumlah' => 200000, 'alasan' => 'Salah siswa']);

    $this->get(route('finance.receipts.show', ['payment' => $payment]))
        ->assertInertia(fn (Assert $page) => $page->where('receipt.status', 'BATAL')->where('receipt.void_reason', 'Salah siswa'));

    // Nomor berikutnya tetap urut (yang batal tidak dipakai ulang).
    $this->getJson(route('finance.cashier.search', ['lembaga' => $this->smp->id, 'q' => 'fajar']))
        ->assertOk()->assertJsonPath('results.0.name', 'Fajar Nugraha')->assertJsonPath('results.0.active', false);
    $this->post(route('finance.payments.store'), fnpPay($this, 'Fajar Nugraha', ['amount' => '300000']))->assertSessionHasNoErrors();

    expect(fnpState('Fajar Nugraha'))->toBe(['2026-07 150000 LUNAS', '2026-08 150000 LUNAS'])
        ->and(DB::table('fn_payments')->where('status', 'SAH')->value('receipt_number'))->toBe('U3-SMP/2026/000002');
});

test('hak akses: Kepala Lembaga hanya melihat; Bendahara lembaga lain 404; tanpa izin keuangan 403', function () {
    $this->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi'))->assertSessionHasNoErrors();
    $payment = (string) DB::table('fn_payments')->value('id');

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($kepala)->get(route('finance.cashier.index', ['siswa' => fnpEnrollment('Ahmad Fauzi')]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canPay', false)->where('student.outstanding', 400000));
    $this->actingAs($kepala)->get(route('finance.receipts.show', ['payment' => $payment]))->assertOk();
    $this->actingAs($kepala)->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi'))->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.payments.void', ['payment' => $payment]), ['reason' => 'X'])->assertForbidden();

    $this->flushSession();
    $this->actingAs($bendaharaMts)->post(route('finance.payments.store'), fnpPay($this, 'Ahmad Fauzi'))->assertNotFound();
    $this->actingAs($bendaharaMts)->get(route('finance.receipts.show', ['payment' => $payment]))->assertNotFound();
    $this->actingAs($bendaharaMts)->getJson(route('finance.cashier.search', ['lembaga' => $this->smp->id, 'q' => 'ahmad']))->assertNotFound();

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.cashier.index'))->assertForbidden();

    expect(DB::table('fn_payments')->count())->toBe(1);
});

test('terbilang rupiah', function () {
    expect(Terbilang::rupiah(0))->toBe('nol rupiah')
        ->and(Terbilang::rupiah(11))->toBe('sebelas rupiah')
        ->and(Terbilang::rupiah(15))->toBe('lima belas rupiah')
        ->and(Terbilang::rupiah(100))->toBe('seratus rupiah')
        ->and(Terbilang::rupiah(1000))->toBe('seribu rupiah')
        ->and(Terbilang::rupiah(1_500_000))->toBe('satu juta lima ratus ribu rupiah')
        ->and(Terbilang::rupiah(2_011_250))->toBe('dua juta sebelas ribu dua ratus lima puluh rupiah')
        ->and(Terbilang::rupiah(175_000))->toBe('seratus tujuh puluh lima ribu rupiah');
});
