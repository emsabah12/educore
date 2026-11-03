<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Guardian\GuardianAccounts;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Tabungan di portal orang tua/wali TB-4 (PRD-005 §6). "Hari ini" Selasa 3 Nov 2026.
 * Ibu Siti Aminah (punya akun) — anak: Ahmad (SMP Unit 3) dan Budi (MTs Unit 1, keluar 31 Agt).
 *   Ahmad: setor 50.000 (1 Nov), tarik 10.000 (2 Nov, diambil Ibu Siti), setor 5.000 (3 Nov) lalu DIBATALKAN → saldo 40.000
 *   Budi:  setor 20.000 di MTs (20 Agt), lalu keluar → saldo 20.000
 * Citra (SMP Unit 3, anak orang lain): setor 7.000.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS');
    $this->actingAs(DevAccounts::user('admin.yayasan'));

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

    $save = function (string $route, string $name, string $organizationId, string $amount, string $date, array $extra = []): void {
        $this->post(route($route), array_merge([
            'organization_id' => $organizationId, 'enrollment_id' => fnxEnrollment($name)->id, 'amount' => $amount, 'entry_date' => $date, 'method' => 'TUNAI',
        ], $extra))->assertRedirect()->assertSessionHasNoErrors();
    };

    $save('finance.savings.deposit', 'Ahmad Fauzi', $this->smp->id, '50000', '2026-11-01');
    $save('finance.savings.withdraw', 'Ahmad Fauzi', $this->smp->id, '10000', '2026-11-02', ['taken_by' => 'Ibu Siti']);
    $save('finance.savings.deposit', 'Ahmad Fauzi', $this->smp->id, '5000', '2026-11-03');
    $save('finance.savings.deposit', 'Budi Santoso', $this->mts->id, '20000', '2026-08-20');
    $save('finance.savings.deposit', 'Citra Lestari', $this->smp->id, '7000', '2026-11-03');

    $this->voided = (string) DB::table('fn_savings_entries')->where('slip_number', 'TAB-U3-SMP/2026/000003')->value('id');
    $this->post(route('finance.savings.void', ['entry' => $this->voided]), ['reason' => 'Salah input'])->assertRedirect()->assertSessionHasNoErrors();

    $budi = fnxEnrollment('Budi Santoso');
    $this->post(route('academic.enrollments.close', ['student' => $budi->student_id, 'enrollment' => $budi->id]), [
        'end_date' => '2026-08-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();

    $ahmad = fnxEnrollment('Ahmad Fauzi');
    $link = (string) DB::table('ac_student_guardians')->where('student_id', $ahmad->student_id)->value('id');
    app(GuardianAccounts::class)->provision($this->tenant->id, $ahmad->student_id, $link);
    $this->siti = User::query()->where('username', '081311112222')->sole();
    $this->siti->forceFill(['must_change_password' => false])->save();

    $this->flushSession();
});

function fnxEnrollment(string $name): object
{
    return DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->first(['e.id', 'e.student_id']);
}

test('orang tua melihat saldo & transaksi sah anaknya per lembaga (termasuk lembaga yang sudah ditinggalkan); slip bisa dibuka', function () {
    $ahmad = fnxEnrollment('Ahmad Fauzi');
    $budi = fnxEnrollment('Budi Santoso');
    $withdrawal = (string) DB::table('fn_savings_entries')->where('slip_number', 'TAB-U3-SMP/2026/000002')->value('id');

    $this->actingAs($this->siti)->get(route('academic.my-children.show', ['student' => $ahmad->student_id]))
        ->assertInertia(fn (Assert $page) => $page->where('links.1', ['key' => 'finance.savings', 'label' => 'Tabungan', 'suffix' => 'tabungan', 'icon' => 'piggy-bank']));

    $this->get(route('finance.child-savings.index', ['student' => $ahmad->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/child-savings')
            ->where('student', ['id' => $ahmad->student_id, 'name' => 'Ahmad Fauzi'])
            ->where('total', 40000)
            ->has('organizations', 1)
            ->where('organizations.0.organization', 'SMP Unit 3')
            ->where('organizations.0.balance', 40000)
            // Setoran yang dibatalkan tidak ditampilkan; tanpa status/alasan batal.
            ->where('organizations.0.entries', [
                [
                    'id' => $withdrawal, 'slip_number' => 'TAB-U3-SMP/2026/000002', 'entry_date' => '2026-11-02', 'kind' => 'TARIK', 'kind_label' => 'Penarikan',
                    'method' => 'Tunai', 'amount' => 10000, 'balance_after' => 40000, 'taken_by' => 'Ibu Siti', 'note' => null,
                ],
                [
                    'id' => (string) DB::table('fn_savings_entries')->where('slip_number', 'TAB-U3-SMP/2026/000001')->value('id'), 'slip_number' => 'TAB-U3-SMP/2026/000001',
                    'entry_date' => '2026-11-01', 'kind' => 'SETOR', 'kind_label' => 'Setoran', 'method' => 'Tunai', 'amount' => 50000, 'balance_after' => 50000,
                    'taken_by' => null, 'note' => null,
                ],
            ]));

    // Budi: tabungan di MTs tetap tampil walau sudah keluar.
    $this->get(route('finance.child-savings.index', ['student' => $budi->student_id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.organization', 'MTs Unit 1')
            ->where('organizations.0.active', false)
            ->where('organizations.0.balance', 20000));

    $this->get(route('finance.child-savings.slip', ['student' => $ahmad->student_id, 'entry' => $withdrawal]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('print/savings-slip')
            ->where('slip.slip_number', 'TAB-U3-SMP/2026/000002')
            ->where('backUrl', "/anak-saya/{$ahmad->student_id}/tabungan")
            ->where('backLabel', 'Kembali ke tabungan'));
});

test('bukan anaknya, slip anak lain, slip batal, atau slip anak sendiri lewat anak lain → 404; petugas tanpa tautan juga 404', function () {
    $ahmad = fnxEnrollment('Ahmad Fauzi');
    $budi = fnxEnrollment('Budi Santoso');
    $citra = fnxEnrollment('Citra Lestari');
    $citraSlip = (string) DB::table('fn_savings_entries')->where('enrollment_id', $citra->id)->value('id');
    $ahmadSlip = (string) DB::table('fn_savings_entries')->where('slip_number', 'TAB-U3-SMP/2026/000001')->value('id');

    $this->actingAs($this->siti);
    $this->get(route('finance.child-savings.index', ['student' => $citra->student_id]))->assertNotFound();
    $this->get(route('finance.child-savings.slip', ['student' => $ahmad->student_id, 'entry' => $citraSlip]))->assertNotFound();
    $this->get(route('finance.child-savings.slip', ['student' => $ahmad->student_id, 'entry' => $this->voided]))->assertNotFound();
    $this->get(route('finance.child-savings.slip', ['student' => $budi->student_id, 'entry' => $ahmadSlip]))->assertNotFound();

    $this->flushSession();
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get(route('finance.child-savings.index', ['student' => $ahmad->student_id]))->assertNotFound();
});

test('anak tanpa tabungan: daftar kosong', function () {
    DB::table('fn_cash_entries')->where('source', 'TABUNGAN')->delete();
    DB::table('fn_savings_entries')->delete();
    DB::table('fn_savings_accounts')->delete();

    $this->actingAs($this->siti)->get(route('finance.child-savings.index', ['student' => fnxEnrollment('Ahmad Fauzi')->student_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('organizations', 0)->where('total', 0));
});
