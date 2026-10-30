<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Persetujuan pengeluaran berjenjang KS-5 (PRD-004 §16). Batas Yayasan Rp 5.000.000.
 * Bendahara SMP mengajukan; Kepala SMP tahap 1; Pimpinan tingkat Yayasan (Bu Ketua) tahap 2.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->actingAs($this->admin);

    $this->get(route('finance.cash.index', ['kas' => $this->smp->id]))->assertOk();
    $this->post(route('finance.approval-limit.set'), ['target' => 'yayasan', 'limit' => '5.000.000'])->assertSessionHasNoErrors();

    CoreFixtures::syncAccess();
    $this->bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($this->bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);
    $this->kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($this->kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $this->ketua = User::factory()->create();
    CoreFixtures::tenantRole(CoreFixtures::member($this->ketua, $this->tenant), 'pimpinan');

    foreach ([['Proyektor kelas', '8000000'], ['Spidol', '300000']] as [$description, $amount]) {
        $this->flushSession();
        $this->actingAs($this->bendahara)->post(route('finance.expenses.store'), [
            'kas' => $this->smp->id, 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'ATK')->value('id'), 'description' => $description, 'amount' => $amount,
        ])->assertSessionHasNoErrors();
    }

    $this->flushSession();
});

function fnbExpense(string $description): object
{
    return DB::table('fn_expense_requests')->where('description', $description)->sole();
}

test('di atas batas: Kepala Lembaga tahap 1 → menunggu Yayasan → Pimpinan Yayasan tahap 2; di bawah batas langsung disetujui', function () {
    $projector = fnbExpense('Proyektor kelas');
    $markers = fnbExpense('Spidol');

    $this->actingAs($this->kepala);
    $this->post(route('finance.expenses.approve', ['expense' => $markers->id]), ['approved_amount' => '300000'])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.expenses.approve', ['expense' => $projector->id]), ['approved_amount' => '7.500.000', 'decision_note' => 'Merek lokal'])->assertSessionHasNoErrors();

    expect(fnbExpense('Spidol')->status)->toBe('DISETUJUI')
        ->and(fnbExpense('Proyektor kelas')->status)->toBe('MENUNGGU_YAYASAN')
        ->and(fnbExpense('Proyektor kelas')->first_approved_amount)->toBe(7500000);

    // Kepala tidak bisa memutus tahap 2; bendahara belum bisa realisasi.
    $this->post(route('finance.expenses.approve', ['expense' => $projector->id]), ['approved_amount' => '7500000'])
        ->assertSessionHasErrors(['form' => 'Pengajuan ini menunggu persetujuan tingkat Yayasan.']);
    $this->get(route('finance.expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('expenses.rows.0.status', 'MENUNGGU_YAYASAN')
            ->where('expenses.rows.0.status_label', 'Menunggu persetujuan Yayasan')
            ->where('expenses.rows.0.can_decide', false)
            ->where('expenses.rows.0.first_decision', ['by' => $this->kepala->person->name, 'at' => '2026-10-30', 'amount' => 7500000, 'note' => 'Merek lokal']));

    $this->flushSession();
    $this->actingAs($this->bendahara);
    $this->post(route('finance.expenses.realize', ['expense' => $projector->id]), [
        'account_id' => (string) DB::table('fn_cash_accounts')->where('organization_id', $this->smp->id)->value('id'), 'entry_date' => '2026-10-30', 'amount' => '1',
    ])->assertSessionHasErrors('form');

    // Pimpinan tingkat Yayasan: kartu Beranda menghitung, lalu menyetujui (boleh menurunkan, maks. jumlah tahap 1).
    $this->flushSession();
    $this->actingAs($this->ketua)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.expenses_pending' && $card['value'] === 1)));
    $this->get(route('finance.expenses.index', ['status' => 'MENUNGGU_YAYASAN']))
        ->assertInertia(fn (Assert $page) => $page->has('expenses.rows', 1)->where('expenses.rows.0.can_decide', true));
    $this->post(route('finance.expenses.approve', ['expense' => $projector->id]), ['approved_amount' => '7500001'])->assertSessionHasErrors('approved_amount');
    $this->post(route('finance.expenses.approve', ['expense' => $projector->id]), ['approved_amount' => '7000000'])->assertSessionHasNoErrors();

    $projector = fnbExpense('Proyektor kelas');
    expect($projector->status)->toBe('DISETUJUI')
        ->and($projector->approved_amount)->toBe(7000000)
        ->and($projector->decided_by_membership_id)->not->toBe($projector->first_decided_by_membership_id);
});

test('penyetuju tingkat Yayasan di tahap 1 langsung final; tahap 2 bisa menolak; tidak boleh orang yang sama; batas 0 = tanpa batas', function () {
    $projector = fnbExpense('Proyektor kelas');

    // Admin Yayasan menyetujui langsung (tingkat Yayasan) → final walau di atas batas.
    $this->actingAs($this->admin)->post(route('finance.expenses.approve', ['expense' => $projector->id]), ['approved_amount' => '8000000'])->assertSessionHasNoErrors();
    expect(fnbExpense('Proyektor kelas')->status)->toBe('DISETUJUI');

    // Pengajuan kedua di atas batas → tahap 1 Kepala → Pimpinan menolak.
    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.expenses.store'), [
        'kas' => $this->smp->id, 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'PEMELIHARAAN')->value('id'), 'description' => 'Renovasi toilet', 'amount' => '12000000',
    ])->assertSessionHasNoErrors();
    $renovation = fnbExpense('Renovasi toilet');

    $this->flushSession();
    $this->actingAs($this->kepala)->post(route('finance.expenses.approve', ['expense' => $renovation->id]), ['approved_amount' => '12000000'])->assertSessionHasNoErrors();

    // Bendahara masih bisa membatalkan selama menunggu Yayasan (diuji di pengajuan lain); Pimpinan menolak.
    $this->flushSession();
    $this->actingAs($this->ketua)->get(route('dashboard'))->assertOk();
    $this->post(route('finance.expenses.reject', ['expense' => $renovation->id]), ['decision_note' => 'Masuk anggaran tahun depan'])->assertRedirect()->assertSessionHasNoErrors();
    expect(fnbExpense('Renovasi toilet')->status)->toBe('DITOLAK');

    // Persetujuan kedua harus orang lain dari penyetuju tahap 1 (disimulasikan: tahap 1 tercatat atas nama Pimpinan).
    $ketuaMembership = (string) Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->ketua->person_id)->value('id');
    DB::table('fn_expense_requests')->where('description', 'Spidol')->update([
        'status' => 'MENUNGGU_YAYASAN', 'approved_amount' => 300000, 'first_approved_amount' => 300000,
        'first_decided_by_membership_id' => $ketuaMembership, 'first_decided_at' => now(),
    ]);
    $this->post(route('finance.expenses.approve', ['expense' => fnbExpense('Spidol')->id]), ['approved_amount' => '300000'])
        ->assertSessionHasErrors(['form' => 'Persetujuan kedua harus oleh orang lain (Anda sudah menyetujui tahap pertama).']);

    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.expenses.cancel', ['expense' => fnbExpense('Spidol')->id]))->assertRedirect()->assertSessionHasNoErrors();
    expect(fnbExpense('Spidol')->status)->toBe('DIBATALKAN');

    // Batas 0 untuk SMP (per lembaga) → tanpa batas.
    $this->flushSession();
    $this->actingAs($this->admin)->post(route('finance.approval-limit.set'), ['target' => 'lembaga', 'organization_id' => $this->smp->id, 'limit' => '0'])->assertSessionHasNoErrors();
    $this->get(route('finance.settings.index'))
        ->assertInertia(fn (Assert $page) => $page->has('approvalLimit.rules'));

    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.expenses.store'), [
        'kas' => $this->smp->id, 'category_id' => (string) DB::table('fn_cash_categories')->where('code', 'ATK')->value('id'), 'description' => 'Laptop TU', 'amount' => '9000000',
    ])->assertSessionHasNoErrors();
    $this->flushSession();
    $this->actingAs($this->kepala)->post(route('finance.expenses.approve', ['expense' => fnbExpense('Laptop TU')->id]), ['approved_amount' => '9000000'])->assertSessionHasNoErrors();
    expect(fnbExpense('Laptop TU')->status)->toBe('DISETUJUI');

    // Pengaturan batas hanya tingkat Yayasan (settings.manage).
    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.approval-limit.set'), ['target' => 'yayasan', 'limit' => '1'])->assertForbidden();
});
