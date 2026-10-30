<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Guardian\GuardianAccounts;
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
 * Tagihan di portal orang tua/wali (PRD-003 FN-5 §19). "Hari ini" Jumat 30 Okt 2026.
 * Ibu Siti Aminah (HP 081311112222, punya akun) — anak: Ahmad (SMP Unit 3) dan Budi (MTs Unit 1, keluar 31 Agt).
 * Citra (SMP Unit 3) — anak orang lain (ayah Joko).
 * SPP SMP Rp 150.000 Juli–November (jatuh tempo tanggal 10; November belum jatuh tempo), SPP MTs Rp 100.000.
 *   Ahmad: bayar Rp 200.000 (05 Okt, U3-SMP/2026/000001), bayar Rp 100.000 (06 Okt) lalu DIBATALKAN.
 *   Citra: bayar Rp 150.000 (05 Okt).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS');
    $year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin);

    $ibu = ['guardians' => [['relation' => 'IBU', 'name' => 'Siti Aminah', 'gender' => 'P', 'phone' => '081311112222']], 'primary_guardian' => 0];

    foreach ([
        ['Ahmad Fauzi', $this->smp, $ibu],
        ['Budi Santoso', $this->mts, $ibu],
        ['Citra Lestari', $this->smp, ['guardians' => [['relation' => 'AYAH', 'name' => 'Joko']], 'primary_guardian' => 0]],
    ] as [$name, $organization, $extra]) {
        $this->post(route('academic.students.store'), array_merge([
            'name' => $name, 'gender' => 'L', 'organization_id' => $organization->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ], $extra))->assertSessionHasNoErrors();
    }

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $year->id, null, '150000', null);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->mts->id, $year->id, null, '100000', null);

    foreach ([$this->smp, $this->mts] as $organization) {
        $this->post(route('finance.invoices.generate'), [
            'organization_id' => $organization->id, 'academic_year_id' => $year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-11',
        ])->assertSessionHasNoErrors();
    }

    $budi = fncEnrollment('Budi Santoso');
    $this->post(route('academic.enrollments.close', ['student' => $budi->student_id, 'enrollment' => $budi->id]), [
        'end_date' => '2026-08-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();

    foreach ([['Ahmad Fauzi', '200000', '2026-10-05'], ['Ahmad Fauzi', '100000', '2026-10-06'], ['Citra Lestari', '150000', '2026-10-05']] as [$name, $amount, $date]) {
        $this->post(route('finance.payments.store'), [
            'organization_id' => $this->smp->id, 'enrollment_id' => fncEnrollment($name)->id, 'amount' => $amount, 'paid_on' => $date, 'method' => 'TUNAI',
        ])->assertSessionHasNoErrors();
    }

    $this->voided = (string) DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000002')->value('id');
    $this->post(route('finance.payments.void', ['payment' => $this->voided]), ['reason' => 'Salah input'])->assertSessionHasNoErrors();

    $ahmad = fncEnrollment('Ahmad Fauzi');
    $link = (string) DB::table('ac_student_guardians')->where('student_id', $ahmad->student_id)->value('id');
    app(GuardianAccounts::class)->provision($this->tenant->id, $ahmad->student_id, $link);
    $this->siti = User::query()->where('username', '081311112222')->sole();
    $this->siti->forceFill(['must_change_password' => false])->save();

    $this->flushSession();
});

function fncEnrollment(string $name): object
{
    return DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->first(['e.id', 'e.student_id']);
}

test('orang tua melihat tombol Tagihan, sisa tagihan, dan riwayat pembayaran sah anaknya; kuitansi bisa dibuka', function () {
    $ahmad = fncEnrollment('Ahmad Fauzi');
    $receipt = (string) DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000001')->value('id');

    $this->actingAs($this->siti)->get(route('academic.my-children.show', ['student' => $ahmad->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('links', [['key' => 'finance.billing', 'label' => 'Tagihan', 'suffix' => 'tagihan', 'icon' => 'receipt']]));

    $this->get(route('finance.child-billing.index', ['student' => $ahmad->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/child-billing')
            ->where('student', ['id' => $ahmad->student_id, 'name' => 'Ahmad Fauzi'])
            ->where('outstanding', 550000)
            ->has('organizations', 1)
            ->where('organizations.0.organization', 'SMP Unit 3')
            ->where('organizations.0.active', true)
            ->has('organizations.0.invoices', 4)
            ->where('organizations.0.invoices.0.description', 'SPP — Agustus 2026')
            ->where('organizations.0.invoices.0.paid_amount', 50000)
            ->where('organizations.0.invoices.0.outstanding', 100000)
            ->where('organizations.0.invoices.0.overdue', true)
            ->where('organizations.0.invoices.3.description', 'SPP — November 2026')
            ->where('organizations.0.invoices.3.overdue', false)
            // Pembayaran yang dibatalkan tidak ditampilkan; tanpa status/alasan batal.
            ->where('organizations.0.payments', [[
                'id' => $receipt, 'receipt_number' => 'U3-SMP/2026/000001', 'paid_on' => '2026-10-05', 'method' => 'Tunai',
                'amount' => 200000, 'items' => ['SPP — Juli 2026', 'SPP — Agustus 2026'],
            ]]));

    $this->get(route('finance.child-billing.receipt', ['student' => $ahmad->student_id, 'payment' => $receipt]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/receipt')
            ->where('receipt.receipt_number', 'U3-SMP/2026/000001')
            ->where('backUrl', "/anak-saya/{$ahmad->student_id}/tagihan")
            ->where('backLabel', 'Kembali ke tagihan'));
});

test('anak yang sudah keluar: lembaga lama tetap tampil selama ada tagihan; tagihan sesudah keluar sudah batal otomatis', function () {
    $budi = fncEnrollment('Budi Santoso');

    $this->actingAs($this->siti)->get(route('finance.child-billing.index', ['student' => $budi->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('outstanding', 200000)
            ->where('organizations.0.organization', 'MTs Unit 1')
            ->where('organizations.0.active', false)
            ->has('organizations.0.invoices', 2)
            ->where('organizations.0.payments', []));
});

test('bukan anaknya, kuitansi anak lain, atau kuitansi batal → 404; petugas tanpa tautan orang tua juga 404', function () {
    $ahmad = fncEnrollment('Ahmad Fauzi');
    $citra = fncEnrollment('Citra Lestari');
    $citraReceipt = (string) DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000003')->value('id');

    $this->actingAs($this->siti);
    $this->get(route('finance.child-billing.index', ['student' => $citra->student_id]))->assertNotFound();
    $this->get(route('finance.child-billing.receipt', ['student' => $citra->student_id, 'payment' => $citraReceipt]))->assertNotFound();
    $this->get(route('finance.child-billing.receipt', ['student' => $ahmad->student_id, 'payment' => $citraReceipt]))->assertNotFound();
    $this->get(route('finance.child-billing.receipt', ['student' => $ahmad->student_id, 'payment' => $this->voided]))->assertNotFound();
    $this->get(route('finance.receipts.show', ['payment' => $citraReceipt]))->assertNotFound();

    $this->flushSession();
    $this->actingAs($this->admin)->get(route('finance.child-billing.index', ['student' => $ahmad->student_id]))->assertNotFound();
});
