<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Online\MidtransException;
use Modules\Finance\Application\Online\MidtransGateway;
use Modules\Finance\Domain\Online\OnlinePaymentMethod;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pembayaran online MG-1 (PRD-006 §6, §7, §12): pengaturan metode & biaya, klien Midtrans, rekening penerima online.
 * Tidak ada panggilan ke internet: klien diuji dengan Http::fake.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', BusinessClock::timezone()));
    config(['services.midtrans.server_key' => null, 'services.midtrans.client_key' => null, 'services.midtrans.is_production' => false]);
    Http::preventStrayRequests();

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->actingAs(DevAccounts::user('admin.yayasan'));
});

/**
 * @param  array<string, array{enabled: string, fee: string}>  $methods
 */
function fnzSave(mixed $test, array $methods): mixed
{
    return $test->post(route('finance.online-settings.update'), ['methods' => $methods]);
}

test('pengaturan: kunci belum dipasang → metode tidak bisa dibuka; sesudah dipasang → metode & biaya tersimpan, tercatat di audit', function () {
    $this->get(route('finance.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onlinePayments.configured', false)
            ->where('onlinePayments.production', false)
            ->has('onlinePayments.methods', 6)
            ->where('onlinePayments.methods.0', [
                'method' => 'BCA_VA', 'label' => 'VA BCA', 'enabled' => false, 'fee_value' => 0, 'fee_is_percent' => false, 'fee_input' => '0', 'fee_label' => 'Tanpa biaya',
            ])
            ->where('onlinePayments.methods.5.method', 'QRIS'));

    fnzSave($this, ['BCA_VA' => ['enabled' => '1', 'fee' => '4000']])
        ->assertSessionHasErrors(['form' => 'Kunci Midtrans belum dipasang di server (.env). Hubungi pengelola server sebelum membuka metode pembayaran.']);
    expect(DB::table('fn_online_payment_methods')->count())->toBe(0);

    config(['services.midtrans.server_key' => 'SB-Mid-server-uji']);

    fnzSave($this, ['QRIS' => ['enabled' => '1', 'fee' => '11']])->assertSessionHasErrors('methods.QRIS.fee');
    fnzSave($this, ['QRIS' => ['enabled' => '1', 'fee' => '0,705']])->assertSessionHasErrors('methods.QRIS.fee');
    fnzSave($this, ['BCA_VA' => ['enabled' => '1', 'fee' => '60000']])->assertSessionHasErrors('methods.BCA_VA.fee');

    fnzSave($this, [
        'BCA_VA' => ['enabled' => '1', 'fee' => '4.000'],
        'BNI_VA' => ['enabled' => '0', 'fee' => '4000'],
        'QRIS' => ['enabled' => '1', 'fee' => '0,7'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('finance.settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onlinePayments.configured', true)
            ->where('onlinePayments.methods.0.enabled', true)
            ->where('onlinePayments.methods.0.fee_value', 4000)
            ->where('onlinePayments.methods.0.fee_label', 'Rp 4.000 per transaksi')
            ->where('onlinePayments.methods.1.enabled', false)
            ->where('onlinePayments.methods.5.fee_value', 70)
            ->where('onlinePayments.methods.5.fee_input', '0,7')
            ->where('onlinePayments.methods.5.fee_label', '0,7% dari tagihan'));

    // Simpan ulang tanpa perubahan tidak menambah audit.
    fnzSave($this, [
        'BCA_VA' => ['enabled' => '1', 'fee' => '4000'],
        'BNI_VA' => ['enabled' => '0', 'fee' => '4000'],
        'QRIS' => ['enabled' => '1', 'fee' => '0.70'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::ONLINE_SETTINGS_UPDATED)->count())->toBe(1);

    // Hanya pengelola pengaturan keuangan tingkat Yayasan.
    CoreFixtures::syncAccess();
    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->flushSession();
    $this->actingAs($bendahara)->get(route('dashboard'))->assertOk();
    fnzSave($this, ['QRIS' => ['enabled' => '0', 'fee' => '0']])->assertForbidden();
});

test('biaya layanan: VA nominal tetap, QRIS persen dibulatkan ke atas; batas waktu bayar', function () {
    expect(OnlinePaymentMethod::BcaVa->fee(150000, 4000))->toBe(4000)
        ->and(OnlinePaymentMethod::Qris->fee(150000, 70))->toBe(1050)
        ->and(OnlinePaymentMethod::Qris->fee(100001, 70))->toBe(701)
        ->and(OnlinePaymentMethod::Qris->fee(150000, 0))->toBe(0)
        ->and(OnlinePaymentMethod::Qris->snapCode())->toBe('other_qris')
        ->and(OnlinePaymentMethod::MandiriBill->snapCode())->toBe('echannel')
        ->and(OnlinePaymentMethod::Qris->expiryMinutes())->toBe(15)
        ->and(OnlinePaymentMethod::BriVa->expiryMinutes())->toBe(1440);
});

test('klien Midtrans: Snap & status ke sandbox dengan Basic auth; gangguan & penolakan jadi pesan ramah; tanda tangan', function () {
    $gateway = app(MidtransGateway::class);

    expect(fn () => $gateway->status('EDU-1'))->toThrow(MidtransException::class, 'Pembayaran online belum disiapkan (kunci Midtrans belum dipasang).')
        ->and($gateway->verifySignature('EDU-1', '200', '150000.00', 'x'))->toBeFalse();

    config(['services.midtrans.server_key' => 'SB-Mid-server-uji']);

    Http::fake([
        'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'tok-123', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-123'], 201),
        'api.sandbox.midtrans.com/v2/EDU-1/status' => Http::response(['order_id' => 'EDU-1', 'transaction_status' => 'pending', 'status_code' => '201'], 200),
        'api.sandbox.midtrans.com/v2/EDU-DOWN/status' => Http::response('', 503),
        'api.sandbox.midtrans.com/v2/EDU-BAD/cancel' => Http::response(['error_messages' => ['Access denied']], 401),
    ]);

    expect($gateway->createTransaction(['transaction_details' => ['order_id' => 'EDU-1', 'gross_amount' => 154000]]))
        ->toBe(['token' => 'tok-123', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-123'])
        ->and($gateway->status('EDU-1')['transaction_status'])->toBe('pending');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('SB-Mid-server-uji:'))
        && $request['transaction_details']['gross_amount'] === 154000);

    expect(fn () => $gateway->status('EDU-DOWN'))->toThrow(MidtransException::class, 'Layanan pembayaran sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.')
        ->and(fn () => $gateway->cancel('EDU-BAD'))->toThrow(MidtransException::class, 'Layanan pembayaran menolak permintaan ini. Hubungi bendahara sekolah.');

    $signature = hash('sha512', 'EDU-1'.'200'.'154000.00'.'SB-Mid-server-uji');
    expect($gateway->verifySignature('EDU-1', '200', '154000.00', $signature))->toBeTrue()
        ->and($gateway->verifySignature('EDU-1', '200', '154001.00', $signature))->toBeFalse()
        ->and($gateway->isProduction())->toBeFalse();

    // Produksi memakai domain tanpa "sandbox".
    config(['services.midtrans.is_production' => true]);
    Http::fake(['api.midtrans.com/v2/EDU-9/status' => Http::response(['transaction_status' => 'settlement'], 200)]);
    expect($gateway->status('EDU-9')['transaction_status'])->toBe('settlement');
});

test('rekening penerima pembayaran online: rekening bank aktif lembaga, satu per lembaga; Kas tunai ditolak', function () {
    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();

    foreach (['BSI SPP', 'BRI Operasional'] as $name) {
        $this->post(route('finance.cash-accounts.store'), [
            'kas' => $this->smp->id, 'name' => $name, 'bank_name' => 'Bank', 'opening_balance' => '0', 'opening_date' => '2026-11-01',
        ])->assertSessionHasNoErrors();
    }

    $account = fn (string $name): string => (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->where('name', $name)->value('id');

    $this->post(route('finance.cash-accounts.online-receiver', ['account' => $account('BSI SPP')]), ['receives' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.cash-accounts.online-receiver', ['account' => $account('BRI Operasional')]), ['receives' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.cash-accounts.online-receiver', ['account' => $account('Kas tunai')]), ['receives' => true])->assertSessionHasErrors('form');

    expect(DB::table('fn_cash_accounts')->where('receives_online', true)->pluck('name')->all())->toBe(['BRI Operasional']);

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('accounts', fn ($accounts): bool => collect($accounts)->where('receives_online', true)->pluck('name')->all() === ['BRI Operasional']));

    $this->post(route('finance.cash-accounts.online-receiver', ['account' => $account('BRI Operasional')]), ['receives' => false])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('fn_cash_accounts')->where('receives_online', true)->count())->toBe(0);
});
