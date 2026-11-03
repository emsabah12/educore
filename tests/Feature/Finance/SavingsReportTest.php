<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Halaman Tabungan TB-2 (PRD-005 §6). "Hari ini" Senin 2 Nov 2026, SMP Unit 3:
 *   Ahmad Fauzi  setor 20 Okt Rp 50.000, tarik 1 Nov Rp 10.000 → saldo Rp 40.000
 *   Budi Santoso setor 2 Nov Rp 25.000, lalu keluar (pindah) → "Sudah keluar, saldo belum diambil"
 *   Citra Dewi   setor 2 Nov Rp 5.000 lalu dibatalkan → saldo Rp 0 (hanya tampil bila "semua")
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->actingAs(DevAccounts::user('admin.yayasan'));

    $enrollments = [];

    foreach (['Ahmad Fauzi', 'Budi Santoso', 'Citra Dewi'] as $name) {
        $this->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
        $enrollments[$name] = DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('p.name', $name)->first(['e.id', 'e.student_id']);
    }

    $this->enrollments = $enrollments;
    $entry = fn (string $name, string $amount, string $date, array $extra = []) => array_merge([
        'organization_id' => $this->smp->id, 'enrollment_id' => $enrollments[$name]->id, 'amount' => $amount, 'entry_date' => $date, 'method' => 'TUNAI',
    ], $extra);

    $this->post(route('finance.savings.deposit'), $entry('Ahmad Fauzi', '50000', '2026-10-20'))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.withdraw'), $entry('Ahmad Fauzi', '10000', '2026-11-01', ['taken_by' => 'Ibu Siti']))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), $entry('Budi Santoso', '25000', '2026-11-02'))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('finance.savings.deposit'), $entry('Citra Dewi', '5000', '2026-11-02'))->assertRedirect()->assertSessionHasNoErrors();
    $citra = (string) DB::table('fn_savings_entries')->where('enrollment_id', $enrollments['Citra Dewi']->id)->value('id');
    $this->post(route('finance.savings.void', ['entry' => $citra]), ['reason' => 'Salah siswa'])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('academic.enrollments.close', ['student' => $enrollments['Budi Santoso']->student_id, 'enrollment' => $enrollments['Budi Santoso']->id]), [
        'end_date' => '2026-11-02', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();
});

test('saldo per siswa: hanya saldo > 0 (bawaan), siswa keluar dikelompokkan, saringan kelas & cari, semua termasuk Rp 0', function () {
    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/savings')
            ->where('organizationId', $this->smp->id)
            ->where('balances.total', 65000)
            ->where('balances.student_count', 2)
            ->where('balances.students.0.name', 'Ahmad Fauzi')
            ->where('balances.students.0.balance', 40000)
            ->where('balances.students.0.last_date', '2026-11-01')
            ->where('balances.students.1.name', 'Budi Santoso')
            ->where('balances.students.1.active', false)
            ->where('classOptions', [['value' => '-', 'label' => 'Tanpa kelas'], ['value' => 'keluar', 'label' => 'Sudah keluar']])
            ->where('summary', fn ($summary): bool => collect($summary)->contains(fn (array $row): bool => $row['organization_id'] === $this->smp->id && $row['students'] === 2 && $row['total'] === 65000))
            ->where('filters', ['kelas' => null, 'cari' => '', 'semua' => false]));

    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'kelas' => 'keluar']))
        ->assertInertia(fn (Assert $page) => $page->where('balances.student_count', 1)->where('balances.total', 25000)->where('filters.kelas', 'keluar'));

    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'kelas' => 'asal']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.kelas', null)->where('balances.student_count', 2));

    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'cari' => 'budi']))
        ->assertInertia(fn (Assert $page) => $page->where('balances.student_count', 1)->where('balances.students.0.name', 'Budi Santoso'));

    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'semua' => '1']))
        ->assertInertia(fn (Assert $page) => $page->where('balances.student_count', 3)->where('balances.students.1.name', 'Citra Dewi')->where('balances.students.1.balance', 0));
});

test('rekap bulanan: saldo awal/akhir dari transaksi sah, transaksi batal ikut tampil bertanda', function () {
    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('month', '2026-11')
            ->where('monthly.opening', 50000)
            ->where('monthly.deposits', 25000)
            ->where('monthly.deposit_count', 1)
            ->where('monthly.withdrawals', 10000)
            ->where('monthly.closing', 65000)
            ->where('monthly.voided_count', 1)
            ->has('monthly.entries', 3)
            ->where('monthly.entries.0.slip_number', 'TAB-U3-SMP/2026/000002')
            ->where('monthly.entries.0.taken_by', 'Ibu Siti')
            ->where('monthly.entries.0.recorded_by', 'Admin Yayasan')
            ->where('monthly.entries.2.status', 'BATAL'));

    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('monthly.opening', 0)->where('monthly.closing', 50000)->has('monthly.entries', 1));

    // Bulan di luar pilihan → bulan berjalan.
    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id, 'bulan' => '2020-01']))
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-11'));
});

test('unduh .xlsx: saldo (saringan ikut) & rekap bulanan; bulan asal-asalan 404', function () {
    $balances = $this->get(route('finance.savings.export-balances', ['lembaga' => $this->smp->id, 'kelas' => 'keluar']))->assertOk();
    expect($balances->headers->get('content-disposition'))->toContain('saldo-tabungan-u3-smp-2026-11-02.xlsx');

    $reader = new Reader;
    $reader->open($balances->getFile()->getPathname());
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    expect($rows[0][0])->toContain('— sudah keluar')
        ->and($rows[2][1])->toBe('Budi Santoso')
        ->and($rows[2][5])->toBe('Sudah keluar')
        ->and($rows[2][6])->toEqual(25000)
        ->and($rows[3][6])->toEqual(25000);

    $monthly = $this->get(route('finance.savings.export-monthly', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))->assertOk();
    expect($monthly->headers->get('content-disposition'))->toContain('rekap-tabungan-u3-smp-2026-11.xlsx');

    $reader = new Reader;
    $reader->open($monthly->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }

    $reader->close();
    expect(array_keys($sheets))->toBe(['Ringkasan', 'Transaksi'])
        ->and($sheets['Ringkasan'][2])->toEqual(['Saldo awal bulan', '', 50000])
        ->and($sheets['Ringkasan'][6])->toEqual(['Saldo akhir bulan', '', 65000])
        ->and($sheets['Transaksi'])->toHaveCount(5)
        ->and($sheets['Transaksi'][4][10])->toBe('Batal')
        ->and($sheets['Transaksi'][4][11])->toBe('Salah siswa');

    $this->get(route('finance.savings.export-monthly', ['lembaga' => $this->smp->id, 'bulan' => '2020-01']))->assertNotFound();
    $this->get(route('finance.savings.export-monthly', ['lembaga' => $this->smp->id]))->assertNotFound();
});

test('hak akses & kartu Beranda: kepala lembaga melihat lembaganya; lembaga lain 404; tanpa izin tabungan 403', function () {
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.savings'
            && $card['value'] === 2 && $card['description'] === 'Rp 65.000 milik 2 siswa.' && $card['href'] === '/keuangan/tabungan')));

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), 'kepala-lembaga');
    $teacher = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($teacher, $this->tenant), $this->smp), 'operator-akademik');

    $this->flushSession();
    $this->actingAs($kepala)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => collect($cards)->contains(fn (array $card): bool => $card['key'] === 'finance.savings' && $card['value'] === 0)));
    $this->get(route('finance.savings.index'))
        ->assertInertia(fn (Assert $page) => $page->has('organizations', 1)->where('balances.student_count', 0));
    $this->get(route('finance.savings.index', ['lembaga' => $this->smp->id]))
        ->assertInertia(fn (Assert $page) => $page->where('organizationId', fn ($id): bool => $id !== $this->smp->id));
    $this->get(route('finance.savings.export-balances', ['lembaga' => $this->smp->id]))->assertNotFound();
    $this->get(route('finance.savings.export-monthly', ['lembaga' => $this->smp->id, 'bulan' => '2026-11']))->assertNotFound();

    $this->flushSession();
    $this->actingAs($teacher)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('cards', fn ($cards): bool => ! collect($cards)->contains('key', 'finance.savings')));
    $this->get(route('finance.savings.index'))->assertForbidden();
});
