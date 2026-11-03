<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Guardian\GuardianAccounts;
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
use Modules\Finance\Application\Online\MidtransException;
use Modules\Finance\Application\Online\MidtransGateway;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\Support\FakeMidtransGateway;

/*
 * Bayar online MG-2 (PRD-006 §4–§5). "Hari ini" Selasa 3 Nov 2026. Midtrans tiruan (tanpa internet).
 * Ibu Siti Aminah (akun portal) — anak: Ahmad (SMP Unit 3). Citra (SMP Unit 3) anak orang lain.
 * SPP Rp 150.000 Juli–November. Metode dibuka: VA BCA (biaya Rp 4.000), QRIS (0,7%).
 * Kas SMP: rekening "BSI Online" = penerima pembayaran online.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', BusinessClock::timezone()));
    Http::preventStrayRequests();
    $this->midtrans = new FakeMidtransGateway;
    app()->instance(MidtransGateway::class, $this->midtrans);

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin);

    $ibu = ['guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222']], 'primary_guardian' => 0];

    foreach ([['Ahmad Fauzi', $ibu], ['Citra Lestari', ['guardians' => [['relation' => 'AYAH', 'name' => 'Joko']], 'primary_guardian' => 0]]] as [$name, $extra]) {
        $this->post(route('academic.students.store'), array_merge([
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ], $extra))->assertSessionHasNoErrors();
    }

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-11',
    ])->assertSessionHasNoErrors();

    foreach ([['BCA_VA', true, 4000], ['QRIS', true, 70], ['BNI_VA', false, 4000]] as [$method, $enabled, $fee]) {
        DB::table('fn_online_payment_methods')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'method' => $method, 'is_enabled' => $enabled, 'fee_value' => $fee, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();
    $this->post(route('finance.cash-accounts.store'), [
        'kas' => $this->smp->id, 'name' => 'BSI Online', 'bank_name' => 'BSI', 'opening_balance' => '0', 'opening_date' => '2026-11-01',
    ])->assertSessionHasNoErrors();
    $this->bsi = (string) DB::table('fn_cash_accounts')->where('name', 'BSI Online')->value('id');
    $this->post(route('finance.cash-accounts.online-receiver', ['account' => $this->bsi]), ['receives' => true])->assertSessionHasNoErrors();

    $this->ahmad = fnyEnrollment('Ahmad Fauzi');
    $link = (string) DB::table('ac_student_guardians')->where('student_id', $this->ahmad->student_id)->value('id');
    app(GuardianAccounts::class)->provision($this->tenant->id, $this->ahmad->student_id, $link);
    $this->siti = User::query()->where('username', '081311112222')->sole();
    $this->siti->forceFill(['must_change_password' => false])->save();

    $this->flushSession();
    $this->actingAs($this->siti);
});

function fnyEnrollment(string $name): object
{
    return DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->first(['e.id', 'e.student_id']);
}

/**
 * @return list<string>
 */
function fnyInvoices(object $enrollment, string ...$periods): array
{
    return DB::table('fn_invoices')->where('enrollment_id', $enrollment->id)->whereIn('period', array_map(fn (string $period): string => $period.'-01', $periods))->orderBy('period')->pluck('id')->map(fn ($id): string => (string) $id)->all();
}

function fnyOrder(mixed $test, array $invoiceIds, string $method): mixed
{
    return $test->post(route('finance.child-online.store', ['student' => $test->ahmad->student_id]), [
        'enrollment_id' => $test->ahmad->id, 'invoice_ids' => $invoiceIds, 'method' => $method,
    ]);
}

/**
 * Kirim notifikasi Midtrans; status "resmi" (yang dicek ulang server) diatur lewat Midtrans tiruan.
 */
function fnyNotify(mixed $test, object $order, string $status, ?string $gross = null, bool $validSignature = true): mixed
{
    $gross ??= $order->gross_amount.'.00';
    $code = $status === 'settlement' ? '200' : ($status === 'pending' ? '201' : '202');
    $test->midtrans->statuses[$order->order_id] = [
        'order_id' => $order->order_id, 'transaction_status' => $status, 'status_code' => $code, 'gross_amount' => $gross,
        'transaction_id' => 'trx-'.$order->order_id, 'settlement_time' => '2026-11-03 10:15:00',
    ];

    return $test->postJson(route('finance.midtrans.webhook'), [
        'order_id' => $order->order_id, 'status_code' => $code, 'gross_amount' => $gross, 'transaction_status' => $status, 'payment_type' => 'bank_transfer',
        'signature_key' => $validSignature ? FakeMidtransGateway::sign($order->order_id, $code, $gross) : str_repeat('0', 128),
    ]);
}

function fnyLatestOrder(): object
{
    return DB::table('fn_online_payments')->orderByDesc('created_at')->orderByDesc('id')->first();
}

test('orang tua membuat pesanan: jumlah & biaya dihitung server, Snap hanya membuka metode itu, diarahkan ke Midtrans; pesanan baru membatalkan yang lama', function () {
    $this->get(route('finance.child-billing.index', ['student' => $this->ahmad->student_id]))
        ->assertInertia(fn (Assert $page) => $page->where('onlineActive', true)->where('onlineOrders', []));

    $this->get(route('finance.child-online.create', ['student' => $this->ahmad->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/child-online-pay')
            ->has('organizations', 1)
            ->has('organizations.0.invoices', 5)
            ->where('methods', [
                ['value' => 'BCA_VA', 'label' => 'VA BCA', 'fee_is_percent' => false, 'fee_value' => 4000, 'fee_label' => 'Rp 4.000 per transaksi'],
                ['value' => 'QRIS', 'label' => 'QRIS', 'fee_is_percent' => true, 'fee_value' => 70, 'fee_label' => '0,7% dari tagihan'],
            ]));

    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07', '2026-08'), 'BCA_VA')
        ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.fnyLatestOrder()->order_id);

    $first = fnyLatestOrder();
    expect($first->status)->toBe('MENUNGGU')
        ->and((int) $first->invoice_amount)->toBe(300000)
        ->and((int) $first->fee_amount)->toBe(4000)
        ->and((int) $first->gross_amount)->toBe(304000)
        ->and($first->order_id)->toStartWith('EDU-U3SMP-20261103-')
        ->and(CarbonImmutable::parse($first->expires_at)->diffInMinutes(now()->toImmutable(), true))->toEqual(1440.0);

    $payload = $this->midtrans->created[0];
    expect($payload['transaction_details'])->toBe(['order_id' => $first->order_id, 'gross_amount' => 304000])
        ->and($payload['enabled_payments'])->toBe(['bca_va'])
        ->and($payload['item_details'])->toHaveCount(3)
        ->and($payload['item_details'][2])->toBe(['id' => 'BIAYA-LAYANAN', 'price' => 4000, 'quantity' => 1, 'name' => 'Biaya layanan VA BCA'])
        ->and($payload['customer_details'])->toBe(['first_name' => 'Ahmad Fauzi'])
        ->and($payload['expiry']['duration'])->toBe(1440)
        ->and($payload['callbacks']['finish'])->toEndWith("/anak-saya/{$this->ahmad->student_id}/bayar-online/selesai");

    // Pesanan kedua (QRIS, 1 tagihan): yang lama dibatalkan juga di Midtrans.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'QRIS')->assertRedirect();
    $second = fnyLatestOrder();

    expect(DB::table('fn_online_payments')->where('id', $first->id)->value('status'))->toBe('DIBATALKAN')
        ->and($this->midtrans->cancelled)->toBe([$first->order_id])
        ->and((int) $second->fee_amount)->toBe(1050)
        ->and((int) $second->gross_amount)->toBe(151050)
        ->and($this->midtrans->created[1]['enabled_payments'])->toBe(['other_qris'])
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::ONLINE_PAYMENT_CREATED)->count())->toBe(2);

    // Isian salah: metode ditutup / tagihan anak lain.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BNI_VA')->assertSessionHasErrors('method');
    fnyOrder($this, fnyInvoices(fnyEnrollment('Citra Lestari'), '2026-07'), 'BCA_VA')->assertSessionHasErrors('invoice_ids');
});

test('notifikasi lunas: tanda tangan & cek ulang status → kuitansi ONLINE otomatis, buku kas penerima online; duplikat aman; jumlah beda diabaikan', function () {
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'QRIS')->assertRedirect();
    $order = fnyLatestOrder();

    fnyNotify($this, $order, 'settlement', validSignature: false)->assertForbidden();
    expect(DB::table('fn_online_payment_events')->where('signature_valid', false)->count())->toBe(1)
        ->and(DB::table('fn_payments')->count())->toBe(0);

    fnyNotify($this, $order, 'settlement', gross: '1.00')->assertOk();
    expect(DB::table('fn_online_payments')->where('id', $order->id)->value('status'))->toBe('MENUNGGU');

    fnyNotify($this, $order, 'settlement')->assertOk()->assertExactJson(['status' => 'ok']);
    fnyNotify($this, $order, 'settlement')->assertOk();

    $paid = DB::table('fn_online_payments')->where('id', $order->id)->first();
    $payment = DB::table('fn_payments')->sole();

    expect($paid->status)->toBe('LUNAS')
        ->and($paid->payment_id)->toBe($payment->id)
        ->and($paid->midtrans_transaction_id)->toBe('trx-'.$order->order_id)
        ->and((int) $paid->overpaid_amount)->toBe(0)
        ->and($payment->method)->toBe('ONLINE')
        ->and((int) $payment->amount)->toBe(150000)
        ->and(substr((string) $payment->paid_on, 0, 10))->toBe('2026-11-03')
        ->and($payment->note)->toBe('Midtrans QRIS · '.$order->order_id)
        ->and($payment->recorded_by_membership_id)->toBeNull()
        ->and(DB::table('fn_invoices')->where('enrollment_id', $this->ahmad->id)->where('period', '2026-07-01')->value('status'))->toBe('LUNAS')
        ->and(DB::table('fn_cash_entries')->where('source', 'PEMBAYARAN')->where('source_id', $payment->id)->value('account_id'))->toBe($this->bsi)
        ->and(DB::table('fn_online_payment_events')->where('signature_valid', true)->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::ONLINE_PAYMENT_PAID)->count())->toBe(1);

    $this->get(route('finance.child-online.show', ['student' => $this->ahmad->student_id, 'order' => $order->id]))
        ->assertInertia(fn (Assert $page) => $page->component('finance/child-online-status')->where('order.status', 'LUNAS')->where('order.payment_id', $payment->id));
    $this->get(route('finance.child-billing.receipt', ['student' => $this->ahmad->student_id, 'payment' => $payment->id]))
        ->assertInertia(fn (Assert $page) => $page->where('receipt.method', 'Pembayaran online')->where('receipt.recorded_by', 'Pembayaran online (otomatis)'));
});

test('kembali dari Midtrans: status dicek sekali; kedaluwarsa / batal oleh orang tua; cek status', function () {
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')->assertRedirect();
    $order = fnyLatestOrder();

    // Masih menunggu → halaman status; tombol Cek status tidak mengubah apa pun.
    $this->get(route('finance.child-online.finish', ['student' => $this->ahmad->student_id, 'order_id' => $order->order_id, 'transaction_status' => 'pending']))
        ->assertRedirect("/anak-saya/{$this->ahmad->student_id}/bayar-online/{$order->id}");
    $this->post(route('finance.child-online.check', ['student' => $this->ahmad->student_id, 'order' => $order->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('fn_online_payments')->where('id', $order->id)->value('status'))->toBe('MENUNGGU');

    $this->get(route('finance.child-online.resume', ['student' => $this->ahmad->student_id, 'order' => $order->id]))
        ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/tok-'.$order->order_id);

    fnyNotify($this, $order, 'expire')->assertOk();
    expect(DB::table('fn_online_payments')->where('id', $order->id)->value('status'))->toBe('KEDALUWARSA')
        ->and(DB::table('fn_payments')->count())->toBe(0);

    // Pesanan baru, lalu dibatalkan orang tua.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')->assertRedirect();
    $next = fnyLatestOrder();
    $this->post(route('finance.child-online.cancel', ['student' => $this->ahmad->student_id, 'order' => $next->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('fn_online_payments')->where('id', $next->id)->value('status'))->toBe('DIBATALKAN')
        ->and($this->midtrans->cancelled)->toBe([$next->order_id]);

    // Pesanan ketiga lunas; orang tua kembali dari Midtrans → status langsung dicek → LUNAS.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')->assertRedirect();
    $third = fnyLatestOrder();
    $this->midtrans->statuses[$third->order_id] = ['transaction_status' => 'settlement', 'gross_amount' => $third->gross_amount.'.00', 'settlement_time' => '2026-11-03 09:30:00'];
    $this->get(route('finance.child-online.finish', ['student' => $this->ahmad->student_id, 'order_id' => $third->order_id]))
        ->assertRedirect("/anak-saya/{$this->ahmad->student_id}/bayar-online/{$third->id}");
    expect(DB::table('fn_online_payments')->where('id', $third->id)->value('status'))->toBe('LUNAS');
});

test('tagihan keburu dibayar di kasir: kuitansi online hanya untuk sisa, kelebihan ditandai', function () {
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07', '2026-08'), 'BCA_VA')->assertRedirect();
    $order = fnyLatestOrder();

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->ahmad->id, 'amount' => '150000', 'paid_on' => '2026-11-03', 'method' => 'TUNAI',
        'invoice_ids' => fnyInvoices($this->ahmad, '2026-08'),
    ])->assertSessionHasNoErrors();

    fnyNotify($this, $order, 'settlement')->assertOk();

    $paid = DB::table('fn_online_payments')->where('id', $order->id)->first();
    expect($paid->status)->toBe('LUNAS')
        ->and((int) $paid->overpaid_amount)->toBe(150000)
        ->and((int) DB::table('fn_payments')->where('id', $paid->payment_id)->value('amount'))->toBe(150000)
        ->and(DB::table('fn_invoices')->where('enrollment_id', $this->ahmad->id)->whereIn('period', ['2026-07-01', '2026-08-01'])->where('status', 'LUNAS')->count())->toBe(2);
});

test('Midtrans tidak bisa dihubungi, metode belum dibuka, bukan anaknya, dan cara bayar ONLINE di kasir: ditolak', function () {
    $this->midtrans->failNext = MidtransException::unreachable();
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')
        ->assertSessionHasErrors(['form' => 'Layanan pembayaran sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.']);
    expect(fnyLatestOrder()->status)->toBe('GAGAL');

    // Gangguan saat cek ulang notifikasi → 503 agar Midtrans mengirim ulang.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')->assertRedirect();
    $order = fnyLatestOrder();
    $this->midtrans->failNext = MidtransException::unreachable();
    $this->postJson(route('finance.midtrans.webhook'), [
        'order_id' => $order->order_id, 'status_code' => '200', 'gross_amount' => $order->gross_amount.'.00', 'transaction_status' => 'settlement',
        'signature_key' => FakeMidtransGateway::sign($order->order_id, '200', $order->gross_amount.'.00'),
    ])->assertStatus(503);

    $citra = fnyEnrollment('Citra Lestari');
    $this->get(route('finance.child-online.create', ['student' => $citra->student_id]))->assertNotFound();
    $this->post(route('finance.child-online.store', ['student' => $this->ahmad->student_id]), [
        'enrollment_id' => $citra->id, 'invoice_ids' => fnyInvoices($citra, '2026-07'), 'method' => 'BCA_VA',
    ])->assertNotFound();

    DB::table('fn_online_payment_methods')->update(['is_enabled' => false]);
    $this->get(route('finance.child-online.create', ['student' => $this->ahmad->student_id]))->assertNotFound();
    $this->get(route('finance.child-billing.index', ['student' => $this->ahmad->student_id]))
        ->assertInertia(fn (Assert $page) => $page->where('onlineActive', false)->has('onlineOrders', 2));

    $this->flushSession();
    $this->actingAs($this->admin)->get(route('finance.cashier.index', ['lembaga' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('methods', fn ($methods): bool => ! collect($methods)->contains('value', 'ONLINE')));
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->ahmad->id, 'amount' => '1000', 'paid_on' => '2026-11-03', 'method' => 'ONLINE',
    ])->assertSessionHasErrors('method');
});

test('webhook tidak memakai perlindungan CSRF (test otomatis melewati CSRF, jadi daftar middleware route diperiksa langsung)', function () {
    $router = app(Router::class);
    $middleware = $router->gatherRouteMiddleware($router->getRoutes()->getByName('finance.midtrans.webhook'));
    $store = $router->gatherRouteMiddleware($router->getRoutes()->getByName('finance.child-online.store'));

    expect(collect($middleware)->contains(fn (mixed $item): bool => is_string($item) && is_a($item, PreventRequestForgery::class, true)))->toBeFalse()
        // Pembanding: route biasa tetap dilindungi.
        ->and(collect($store)->contains(fn (mixed $item): bool => is_string($item) && is_a($item, PreventRequestForgery::class, true)))->toBeTrue();
});

test('petugas (MG-3): daftar & rincian pesanan, kelebihan bayar ditandai dikembalikan, kartu Beranda, Kasir & rekap', function () {
    // Pesanan Juli + Agustus; Agustus keburu dibayar di kasir → kelebihan Rp 150.000.
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07', '2026-08'), 'BCA_VA')->assertRedirect();
    $order = fnyLatestOrder();

    $this->flushSession();
    $this->actingAs($this->admin);
    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->where('onlinePending.id', $order->id)->where('onlinePending.gross_amount', 304000));
    $this->post(route('finance.payments.store'), [
        'organization_id' => $this->smp->id, 'enrollment_id' => $this->ahmad->id, 'amount' => '150000', 'paid_on' => '2026-11-03', 'method' => 'TUNAI',
        'invoice_ids' => fnyInvoices($this->ahmad, '2026-08'),
    ])->assertSessionHasNoErrors();

    // Petugas menekan "Cek status" → Midtrans bilang lunas.
    $this->midtrans->statuses[$order->order_id] = ['transaction_status' => 'settlement', 'gross_amount' => '304000.00', 'settlement_time' => '2026-11-03 10:00:00'];
    $this->post(route('finance.online-payments.check', ['order' => $order->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('fn_online_payments')->where('id', $order->id)->value('status'))->toBe('LUNAS');

    $this->get(route('finance.cashier.index', ['lembaga' => $this->smp->id, 'siswa' => $this->ahmad->id]))
        ->assertInertia(fn (Assert $page) => $page->where('onlinePending', null));

    $this->get(route('finance.online-payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/online-payments')
            ->where('orders.total', 1)
            ->where('orders.rows.0.student', 'Ahmad Fauzi')
            ->where('orders.rows.0.organization', 'SMP Unit 3')
            ->where('orders.rows.0.overpaid_amount', 150000)
            ->where('orders.rows.0.overpaid_settled', false)
            ->where('overpaid', ['count' => 1, 'amount' => 150000]));
    $this->get(route('finance.online-payments.index', ['status' => 'KELEBIHAN']))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
    $this->get(route('finance.online-payments.index', ['status' => 'MENUNGGU']))->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.online_overpaid'
            && $card['value'] === 1 && $card['description'] === 'Rp 150.000 perlu dikembalikan ke orang tua.' && $card['tone'] === 'warning')));

    $this->get(route('finance.online-payments.show', ['order' => $order->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/online-payment-show')
            ->where('student.name', 'Ahmad Fauzi')
            ->where('receiptNumber', 'U3-SMP/2026/000002')
            ->where('canManage', true)
            ->has('events', 0));

    $this->post(route('finance.online-payments.settle-overpaid', ['order' => $order->id]), ['note' => 'Dikembalikan tunai ke Ibu Siti'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.online-payments.settle-overpaid', ['order' => $order->id]), [])->assertSessionHasErrors('form');

    expect(DB::table('fn_online_payments')->where('id', $order->id)->value('overpaid_settled_at'))->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::ONLINE_OVERPAID_SETTLED)->count())->toBe(1);
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'finance.online_overpaid')));

    // Rekap penerimaan: kolom Online.
    $this->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.totals.online', 150000)->where('summary.totals.cash', 150000)->where('summary.totals.transfer', 0));
});

test('petugas (MG-3): kepala lembaga hanya melihat; bendahara lembaga lain tidak melihat', function () {
    fnyOrder($this, fnyInvoices($this->ahmad, '2026-07'), 'BCA_VA')->assertRedirect();
    $order = fnyLatestOrder();

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $other = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($other, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($kepala)->get(route('dashboard'))->assertOk();
    $this->get(route('finance.online-payments.index'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 1));
    $this->get(route('finance.online-payments.show', ['order' => $order->id]))->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post(route('finance.online-payments.check', ['order' => $order->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs($other)->get(route('dashboard'))->assertOk();
    $this->get(route('finance.online-payments.index'))->assertInertia(fn (Assert $page) => $page->where('orders.total', 0));
    $this->get(route('finance.online-payments.show', ['order' => $order->id]))->assertNotFound();
    $this->post(route('finance.online-payments.settle-overpaid', ['order' => $order->id]))->assertNotFound();
});
