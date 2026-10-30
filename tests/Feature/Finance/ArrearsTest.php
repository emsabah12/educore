<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
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
 * Tunggakan & kartu Beranda (PRD-003 FN-4a §17). "Hari ini" Jumat 30 Okt 2026, SMP Unit 3,
 * SPP Rp 150.000/bulan Juli–November 2026 jatuh tempo tanggal 10 (November belum jatuh tempo):
 *   Ahmad (7-A)  — bayar Rp 200.000 → menunggak Agt (sisa 100.000), Sep, Okt = Rp 400.000
 *   Citra (9-A)  — lunas Juli–Oktober → tidak menunggak
 *   Dewi (tanpa kelas) — Juli–Oktober = Rp 600.000
 *   Fajar (7-A, keluar 31 Agt) — Juli, Agustus = Rp 300.000 (September dst. batal otomatis)
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

    foreach ([['7-A', 7], ['9-A', 9]] as [$name, $grade]) {
        $this->post(route('academic.classes.store'), [
            'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'grade' => $grade, 'name' => $name, 'homeroom_employee_id' => '',
        ])->assertSessionHasNoErrors();
    }

    $class = fn (string $name): string => SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole()->id;

    foreach ([['Ahmad Fauzi', $class('7-A')], ['Citra Lestari', $class('9-A')], ['Dewi Sartika', null], ['Fajar Nugraha', $class('7-A')]] as [$name, $classId]) {
        $this->post(route('academic.students.store'), array_filter([
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU', 'class_id' => $classId,
        ]))->assertSessionHasNoErrors();
    }

    $spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    $this->post(route('finance.invoices.generate'), [
        'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $spp->id, 'from' => '2026-07', 'to' => '2026-11',
    ])->assertSessionHasNoErrors();

    $fajar = DB::table('ac_enrollments')->where('id', fnaEnrollment('Fajar Nugraha'))->first(['id', 'student_id']);
    $this->post(route('academic.enrollments.close', ['student' => $fajar->student_id, 'enrollment' => $fajar->id]), [
        'end_date' => '2026-08-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();

    foreach ([['Ahmad Fauzi', '200000'], ['Citra Lestari', '600000']] as [$name, $amount]) {
        $this->post(route('finance.payments.store'), [
            'organization_id' => $this->smp->id, 'enrollment_id' => fnaEnrollment($name), 'amount' => $amount, 'paid_on' => '2026-10-30', 'method' => 'TUNAI',
        ])->assertSessionHasNoErrors();
    }
});

function fnaEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('e.id');
}

test('tunggakan: hanya yang lewat jatuh tempo, semua siswa termasuk yang keluar, urut kelas lalu nama, saring kelas', function () {
    $this->get(route('finance.arrears.index', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/arrears')
            ->where('today', '2026-10-30')
            ->where('summary', [['organization_id' => $this->smp->id, 'students' => 3, 'invoices' => 9, 'total' => 1300000, 'name' => 'SMP Unit 3']])
            ->where('organizationId', $this->smp->id)
            ->where('classOptions', [['value' => '7-A', 'label' => '7-A'], ['value' => '-', 'label' => 'Tanpa kelas'], ['value' => 'keluar', 'label' => 'Sudah keluar']])
            ->where('classFilter', null)
            ->where('arrears.total', 1300000)
            ->where('arrears.student_count', 3)
            ->where('arrears.invoice_count', 9)
            ->where('arrears.classes', [
                ['group' => '7-A', 'label' => '7-A', 'students' => 1, 'invoices' => 3, 'total' => 400000],
                ['group' => '-', 'label' => 'Tanpa kelas', 'students' => 1, 'invoices' => 4, 'total' => 600000],
                ['group' => 'keluar', 'label' => 'Sudah keluar', 'students' => 1, 'invoices' => 2, 'total' => 300000],
            ])
            ->where('arrears.students.0.name', 'Ahmad Fauzi')
            ->where('arrears.students.0.total', 400000)
            ->where('arrears.students.0.invoices', 3)
            ->where('arrears.students.0.oldest_due', '2026-08-10')
            ->where('arrears.students.1.name', 'Dewi Sartika')
            ->where('arrears.students.1.class', null)
            ->where('arrears.students.2.name', 'Fajar Nugraha')
            ->where('arrears.students.2.active', false)
            ->where('arrears.students.2.class', null)
            ->where('arrears.students.2.total', 300000));

    $this->get(route('finance.arrears.index', ['lembaga' => $this->smp->id, 'kelas' => '-']))
        ->assertInertia(fn (Assert $page) => $page->where('classFilter', '-')->where('arrears.total', 600000)->has('arrears.students', 1)->has('arrears.classes', 1));
    $this->get(route('finance.arrears.index', ['lembaga' => $this->smp->id, 'kelas' => 'keluar']))
        ->assertInertia(fn (Assert $page) => $page->where('arrears.total', 300000)->where('arrears.students.0.name', 'Fajar Nugraha'));

    // Kelas yang tidak ada di daftar diabaikan (semua kelas).
    $this->get(route('finance.arrears.index', ['lembaga' => $this->smp->id, 'kelas' => '9-A']))
        ->assertInertia(fn (Assert $page) => $page->where('classFilter', null)->where('arrears.total', 1300000));
});

test('unduh .xlsx: tiga lembar per siswa, per kelas, rincian; saringan kelas ikut', function () {
    $filtered = $this->get(route('finance.arrears.export', ['lembaga' => $this->smp->id, 'kelas' => 'keluar']))->assertOk();
    $reader = new XlsxReader;
    $reader->open($filtered->baseResponse->getFile()->getPathname());

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = iterator_to_array($sheet->getRowIterator(), false);
        expect($rows[0]->cells[0]->getValue())->toBe('Tunggakan SMP Unit 3 per 2026-10-30 — sudah keluar')
            ->and($rows[2]->cells[1]->getValue())->toBe('Fajar Nugraha')
            ->and(count($rows))->toBe(4);

        break;
    }

    $reader->close();

    $response = $this->get(route('finance.arrears.export', ['lembaga' => $this->smp->id]))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('tunggakan-u3-smp-2026-10-30.xlsx');

    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Per siswa', 'Per kelas', 'Rincian'])
        ->and($sheets['Per siswa'][0][0])->toBe('Tunggakan SMP Unit 3 per 2026-10-30')
        ->and($sheets['Per siswa'][2])->toBe([1, 'Ahmad Fauzi', '', '', '7-A', 'Aktif', 3, 400000, '2026-08-10'])
        ->and($sheets['Per siswa'][3][1])->toBe('Dewi Sartika')
        ->and($sheets['Per siswa'][4][1])->toBe('Fajar Nugraha')
        ->and($sheets['Per siswa'][4][5])->toBe('Sudah keluar')
        ->and($sheets['Per siswa'][5])->toBe(['', 'Total', '', '', '', '', '', 1300000, ''])
        ->and($sheets['Per kelas'][2])->toBe([1, '7-A', 1, 3, 400000])
        ->and($sheets['Per kelas'][4])->toBe([3, 'Sudah keluar', 1, 2, 300000])
        ->and(count($sheets['Rincian']))->toBe(2 + 9)
        ->and($sheets['Rincian'][2])->toBe([1, 'Ahmad Fauzi', '', '7-A', 'SPP — Agustus 2026', '2026-08-10', 150000, 50000, 100000]);
});

test('hak akses & kartu Beranda: sesuai cakupan; tanpa izin keuangan tidak ada halaman maupun kartu', function () {
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card === [
            'key' => 'finance.arrears', 'title' => 'Tunggakan siswa', 'value' => 3,
            'description' => 'Rp 1.300.000 lewat jatuh tempo.', 'href' => '/keuangan/tunggakan', 'tone' => 'warning',
        ])));

    CoreFixtures::syncAccess();
    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($bendaharaMts)->get(route('finance.arrears.index', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations', 1)
            ->where('organizationId', CoreFixtures::node($this->tenant, 'U1-MTS')->id)
            ->where('summary', [])
            ->where('arrears.student_count', 0));
    $this->actingAs($bendaharaMts)->get(route('finance.arrears.export', ['lembaga' => $this->smp->id]))->assertNotFound();
    $this->actingAs($bendaharaMts)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.arrears'
            && $card['value'] === 0 && $card['tone'] === 'default' && $card['description'] === 'Tidak ada tagihan siswa yang lewat jatuh tempo.')));

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.arrears.index'))->assertForbidden();
    $this->actingAs($guru)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'finance.arrears')));
});
