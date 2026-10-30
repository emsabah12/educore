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
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekap penerimaan (PRD-003 FN-4b §18). "Hari ini" Jumat 30 Okt 2026. SPP SMP Unit 3 Rp 150.000, MTs Unit 1 Rp 100.000
 * (Juli–Oktober), Seragam Ahmad Rp 300.000. Pembayaran (tanggal bayar):
 *   05 Okt  SMP  Ahmad  Tunai     150.000  SPP Juli                     U3-SMP/2026/000001
 *   05 Okt  SMP  Citra  Transfer  300.000  SPP Juli, Agustus            U3-SMP/2026/000002
 *   12 Okt  SMP  Ahmad  Tunai     100.000  Seragam (cicil)              U3-SMP/2026/000003
 *   12 Okt  MTs  Budi   Tunai     200.000  SPP Juli, Agustus            U1-MTS/2026/000001
 *   20 Okt  SMP  Citra  Tunai     150.000  SPP September — DIBATALKAN   U3-SMP/2026/000004
 *   28 Sep  SMP  Ahmad  Transfer   50.000  SPP Agustus (sebagian)       U3-SMP/2026/000005
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
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->actingAs($this->admin);

    foreach ([['Ahmad Fauzi', $this->smp], ['Citra Lestari', $this->smp], ['Budi Santoso', $this->mts]] as [$name, $organization]) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $organization->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
    }

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    $seragam = app(ManageFeeTypes::class)->create($this->tenant->id, 'SERAGAM', 'Seragam', FeeKind::Once);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->mts->id, $this->year->id, null, '100000', null);
    app(ManageFeeRates::class)->set($this->tenant->id, $seragam->id, $this->smp->id, $this->year->id, null, '300000', null);

    foreach ([$this->smp, $this->mts] as $organization) {
        $this->post(route('finance.invoices.generate'), [
            'organization_id' => $organization->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-10',
        ])->assertSessionHasNoErrors();
    }

    $this->post(route('finance.invoices.generate-once'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $seragam->id,
        'target' => 'siswa', 'enrollment_id' => fnrEnrollment('Ahmad Fauzi'), 'due_date' => '2026-11-15',
    ])->assertSessionHasNoErrors();
    $seragamInvoice = (string) DB::table('fn_invoices')->where('fee_type_id', $seragam->id)->value('id');

    foreach ([
        [$this->smp, 'Ahmad Fauzi', '150000', '2026-10-05', 'TUNAI', null],
        [$this->smp, 'Citra Lestari', '300000', '2026-10-05', 'TRANSFER', null],
        [$this->smp, 'Ahmad Fauzi', '100000', '2026-10-12', 'TUNAI', [$seragamInvoice]],
        [$this->mts, 'Budi Santoso', '200000', '2026-10-12', 'TUNAI', null],
        [$this->smp, 'Citra Lestari', '150000', '2026-10-20', 'TUNAI', null],
        [$this->smp, 'Ahmad Fauzi', '50000', '2026-09-28', 'TRANSFER', null],
    ] as [$organization, $name, $amount, $date, $method, $invoices]) {
        $this->post(route('finance.payments.store'), array_filter([
            'organization_id' => $organization->id, 'enrollment_id' => fnrEnrollment($name), 'amount' => $amount, 'paid_on' => $date, 'method' => $method, 'invoice_ids' => $invoices,
        ]))->assertSessionHasNoErrors();
    }

    $voided = (string) DB::table('fn_payments')->where('receipt_number', 'U3-SMP/2026/000004')->value('id');
    $this->post(route('finance.payments.void', ['payment' => $voided]), ['reason' => 'Uang palsu'])->assertSessionHasNoErrors();
});

function fnrEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('e.id');
}

test('rekap bulan: semua lembaga bawaan; per hari, per jenis biaya, per lembaga; yang batal tidak dihitung', function () {
    $this->get(route('finance.receipt-recap.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/receipt-recap')
            ->where('organizationId', 'semua')
            ->where('organizations.0', ['value' => 'semua', 'label' => 'Semua lembaga'])
            ->where('month', '2026-10')
            ->where('months.0', ['value' => '2026-10', 'label' => 'Oktober 2026'])
            ->has('months', 25)
            ->where('day', null)
            ->where('summary.totals', ['count' => 4, 'amount' => 750000, 'cash' => 450000, 'transfer' => 300000, 'voided_count' => 1, 'voided_amount' => 150000])
            ->where('summary.days', [
                ['date' => '2026-10-05', 'count' => 2, 'cash' => 150000, 'transfer' => 300000, 'total' => 450000],
                ['date' => '2026-10-12', 'count' => 2, 'cash' => 300000, 'transfer' => 0, 'total' => 300000],
            ])
            ->where('summary.fee_types', [['name' => 'SPP', 'amount' => 650000], ['name' => 'Seragam', 'amount' => 100000]])
            ->where('summary.organizations', [
                ['organization_id' => $this->smp->id, 'count' => 3, 'total' => 550000, 'name' => 'SMP Unit 3'],
                ['organization_id' => $this->mts->id, 'count' => 1, 'total' => 200000, 'name' => 'MTs Unit 1'],
            ])
            ->where('transactions.total', 5));

    // Satu tanggal: transaksi hari itu saja, urut nomor kuitansi; ringkasan tetap sebulan.
    $this->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-10', 'tanggal' => '2026-10-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizationId', $this->smp->id)
            ->where('day', '2026-10-05')
            ->where('summary.totals.amount', 550000)
            ->where('summary.organizations', [])
            ->where('transactions.total', 2)
            ->where('transactions.rows.0.receipt_number', 'U3-SMP/2026/000001')
            ->where('transactions.rows.0.name', 'Ahmad Fauzi')
            ->where('transactions.rows.0.items', ['SPP — Juli 2026'])
            ->where('transactions.rows.0.recorded_by', 'Admin Yayasan')
            ->where('transactions.rows.1.method', 'Transfer bank')
            ->where('transactions.rows.1.items', ['SPP — Juli 2026', 'SPP — Agustus 2026']));

    $this->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id, 'tanggal' => '2026-10-20']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.rows.0.status', 'BATAL')
            ->where('transactions.rows.0.void_reason', 'Uang palsu'));

    // Bulan lain; tanggal di luar bulan diabaikan; bulan di luar pilihan → bulan ini.
    $this->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-09', 'tanggal' => '2026-10-05']))
        ->assertInertia(fn (Assert $page) => $page->where('day', null)->where('summary.totals.amount', 50000)->where('summary.totals.transfer', 50000));
    $this->get(route('finance.receipt-recap.index', ['bulan' => '2030-01']))
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
});

test('unduh .xlsx: harian, per jenis biaya, per lembaga, transaksi', function () {
    $response = $this->get(route('finance.receipt-recap.export', ['lembaga' => 'semua', 'bulan' => '2026-10']))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('rekap-penerimaan-semua-lembaga-2026-10.xlsx');

    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Harian', 'Per jenis biaya', 'Per lembaga', 'Transaksi'])
        ->and($sheets['Harian'][0][0])->toBe('Rekap penerimaan semua lembaga — Oktober 2026')
        ->and($sheets['Harian'][2])->toBe([1, '2026-10-05', 2, 150000, 300000, 450000])
        ->and($sheets['Harian'][4])->toBe(['', 'Total', 4, 450000, 300000, 750000])
        ->and(end($sheets['Harian'])[1])->toBe('Dibatalkan (tidak dihitung): 1 transaksi, Rp 150.000')
        ->and($sheets['Per jenis biaya'][2])->toBe([1, 'SPP', 650000])
        ->and($sheets['Per lembaga'][3])->toBe([2, 'MTs Unit 1', 1, 200000])
        ->and(count($sheets['Transaksi']))->toBe(2 + 5)
        ->and($sheets['Transaksi'][2])->toBe([1, '2026-10-05', 'U3-SMP/2026/000001', 'SMP Unit 3', 'Ahmad Fauzi', '', 'Tunai', 150000, 'SPP — Juli 2026', 'Admin Yayasan', 'Sah', ''])
        ->and($sheets['Transaksi'][6][10])->toBe('Batal');

    $single = $this->get(route('finance.receipt-recap.export', ['lembaga' => $this->smp->id, 'bulan' => '2026-10']))->assertOk();
    expect($single->headers->get('content-disposition'))->toContain('rekap-penerimaan-u3-smp-2026-10.xlsx');

    $this->get(route('finance.receipt-recap.export', ['lembaga' => $this->smp->id, 'bulan' => '2030-01']))->assertNotFound();
});

test('hak akses: Bendahara hanya lembaganya (lembaga lain diabaikan, unduhan 404); tanpa izin keuangan 403', function () {
    CoreFixtures::syncAccess();
    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), $this->mts), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($bendaharaMts)->get(route('finance.receipt-recap.index', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations', [['value' => $this->mts->id, 'label' => 'MTs Unit 1']])
            ->where('organizationId', $this->mts->id)
            ->where('summary.totals.amount', 200000)
            ->where('transactions.total', 1));
    $this->actingAs($bendaharaMts)->get(route('finance.receipt-recap.export', ['lembaga' => $this->smp->id, 'bulan' => '2026-10']))->assertNotFound();
    $this->actingAs($bendaharaMts)->get(route('finance.receipt-recap.export', ['lembaga' => 'semua', 'bulan' => '2026-10']))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=rekap-penerimaan-u1-mts-2026-10.xlsx');

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.receipt-recap.index'))->assertForbidden();
    $this->actingAs($guru)->get(route('finance.receipt-recap.export', ['lembaga' => 'semua', 'bulan' => '2026-10']))->assertNotFound();
});
