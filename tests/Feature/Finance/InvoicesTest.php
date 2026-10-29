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
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Tagihan bulanan, jatuh tempo, daftar, batal (PRD-003 §4, FN-2a). "Hari ini" Kamis 29 Okt 2026;
 * tahun ajaran 2026/2027 (Jul 2026 – Jun 2027).
 *
 * SMP Unit 3, tarif SPP: semua tingkat Rp 150.000, tingkat 9 Rp 175.000.
 *   Ahmad  — 7-A, potongan 50%                      → Rp 75.000/bulan
 *   Citra  — 9-A, potongan nominal Rp 200.000       → Rp 0 (langsung Lunas)
 *   Dewi   — belum masuk kelas                       → Rp 150.000 (tarif semua tingkat)
 *   Eko    — masuk 5 Okt 2026, 7-A                   → mulai Oktober
 *   Fajar  — 7-A, keluar 31 Agt 2026                 → hanya Juli & Agustus
 * Jatuh tempo SMP Unit 3: tanggal 15.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00:00', BusinessClock::timezone()));

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

    foreach ([
        ['Ahmad Fauzi', '2026-07-13', $class('7-A')],
        ['Citra Lestari', '2026-07-13', $class('9-A')],
        ['Dewi Sartika', '2026-07-13', null],
        ['Eko Prasetyo', '2026-10-05', $class('7-A')],
        ['Fajar Nugraha', '2026-07-13', $class('7-A')],
    ] as [$name, $start, $classId]) {
        $this->post(route('academic.students.store'), array_filter([
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->smp->id, 'start_date' => $start, 'entry_type' => 'BARU', 'class_id' => $classId,
        ]))->assertSessionHasNoErrors();
    }

    $fajar = DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', 'Fajar Nugraha')->first(['e.id', 'e.student_id']);
    $this->post(route('academic.enrollments.close', ['student' => $fajar->student_id, 'enrollment' => $fajar->id]), [
        'end_date' => '2026-08-31', 'end_reason' => 'PINDAH',
    ])->assertSessionHasNoErrors();

    $this->spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    app(ManageFeeRates::class)->set($this->tenant->id, $this->spp->id, $this->smp->id, $this->year->id, null, '150000', null);
    app(ManageFeeRates::class)->set($this->tenant->id, $this->spp->id, $this->smp->id, $this->year->id, 9, '175000', null);

    foreach ([['Ahmad Fauzi', 'PERSEN', '50'], ['Citra Lestari', 'NOMINAL', '200000']] as [$name, $kind, $value]) {
        $this->post(route('finance.discounts.store'), [
            'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'enrollment_id' => fnvEnrollment($name),
            'fee_type_id' => $this->spp->id, 'kind' => $kind, 'value' => $value, 'reason' => 'Beasiswa',
        ])->assertSessionHasNoErrors();
    }

    $this->post(route('finance.due-day.set'), ['target' => 'lembaga', 'organization_id' => $this->smp->id, 'day' => 15])->assertSessionHasNoErrors();
});

function fnvEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('e.id');
}

/**
 * @return array<string, string>
 */
function fnvInput(mixed $test, string $from, string $to): array
{
    return ['organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'fee_type_id' => $test->spp->id, 'from' => $from, 'to' => $to];
}

/**
 * @return list<string> "Nama Y-m Rp status"
 */
function fnvInvoices(): array
{
    return DB::table('fn_invoices as i')
        ->join('ac_enrollments as e', 'e.id', '=', 'i.enrollment_id')->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')->join('persons as p', 'p.id', '=', 'm.person_id')
        ->orderBy('p.name')->orderBy('i.period')
        ->get(['p.name', 'i.period', 'i.amount', 'i.status'])
        ->map(fn ($row): string => explode(' ', (string) $row->name)[0].' '.substr((string) $row->period, 0, 7).' '.$row->amount.' '.$row->status)
        ->all();
}

test('pratinjau lalu buat Juli–September: siswa aktif per bulan, tarif tingkat, potongan, Rp 0 langsung lunas, jatuh tempo; audit', function () {
    $this->getJson(route('finance.invoices.preview', fnvInput($this, '2026-07', '2026-09')))
        ->assertOk()
        ->assertJsonPath('summary.students', 4)
        ->assertJsonPath('summary.created', 11)
        ->assertJsonPath('summary.free', 3)
        ->assertJsonPath('summary.total', 975000)
        ->assertJsonPath('summary.total_label', 'Rp 975.000')
        ->assertJsonPath('summary.months', ['Juli 2026', 'Agustus 2026', 'September 2026']);
    expect(DB::table('fn_invoices')->count())->toBe(0);

    $this->post(route('finance.invoices.generate'), fnvInput($this, '2026-07', '2026-09'))->assertSessionHasNoErrors();

    expect(fnvInvoices())->toBe([
        'Ahmad 2026-07 75000 TERBUKA', 'Ahmad 2026-08 75000 TERBUKA', 'Ahmad 2026-09 75000 TERBUKA',
        'Citra 2026-07 0 LUNAS', 'Citra 2026-08 0 LUNAS', 'Citra 2026-09 0 LUNAS',
        'Dewi 2026-07 150000 TERBUKA', 'Dewi 2026-08 150000 TERBUKA', 'Dewi 2026-09 150000 TERBUKA',
        'Fajar 2026-07 150000 TERBUKA', 'Fajar 2026-08 150000 TERBUKA',
    ]);

    $citra = DB::table('fn_invoices')->where('enrollment_id', fnvEnrollment('Citra Lestari'))->orderBy('period')->first();
    expect($citra->base_amount)->toBe(175000)
        ->and($citra->discount_amount)->toBe(175000)
        ->and($citra->discount_label)->toBe('Rp 200.000')
        ->and($citra->description)->toBe('SPP — Juli 2026')
        ->and(substr((string) $citra->due_date, 0, 10))->toBe('2026-07-15');

    $audit = json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICES_GENERATED)->sole()->changes, true);
    expect($audit['jumlah'])->toBe(11)->and($audit['total'])->toBe(975000);

    // Diulang sampai Oktober: yang sudah ada dilewati; Eko mulai Oktober.
    $this->getJson(route('finance.invoices.preview', fnvInput($this, '2026-07', '2026-10')))
        ->assertJsonPath('summary.existing', 11)
        ->assertJsonPath('summary.created', 4);
    $this->post(route('finance.invoices.generate'), fnvInput($this, '2026-07', '2026-10'))->assertSessionHasNoErrors();
    expect(DB::table('fn_invoices')->count())->toBe(15)
        ->and(DB::table('fn_invoices')->where('enrollment_id', fnvEnrollment('Eko Prasetyo'))->pluck('period')->map(fn ($period) => substr((string) $period, 0, 7))->all())->toBe(['2026-10']);
});

test('siswa tanpa tarif tingkatnya dilewati & disebut; isian salah ditolak', function () {
    $allGrades = (string) DB::table('fn_fee_rates')->whereNull('grade')->value('id');
    app(ManageFeeRates::class)->remove($this->tenant->id, $allGrades);

    $this->getJson(route('finance.invoices.preview', fnvInput($this, '2026-09', '2026-09')))
        ->assertJsonPath('summary.created', 1)
        ->assertJsonPath('summary.missing_count', 2)
        ->assertJsonPath('summary.missing', [['name' => 'Ahmad Fauzi', 'class' => '7-A'], ['name' => 'Dewi Sartika', 'class' => null]]);

    $this->getJson(route('finance.invoices.preview', fnvInput($this, '2026-10', '2026-09')))->assertStatus(422)->assertJsonValidationErrors('from');
    $this->getJson(route('finance.invoices.preview', fnvInput($this, '2027-07', '2027-08')))->assertStatus(422)->assertJsonValidationErrors(['from' => 'Bulan harus di dalam tahun ajaran.']);

    $sekali = app(ManageFeeTypes::class)->create($this->tenant->id, 'SERAGAM', 'Seragam', FeeKind::Once);
    $this->getJson(route('finance.invoices.preview', [...fnvInput($this, '2026-09', '2026-09'), 'fee_type_id' => $sekali->id]))
        ->assertStatus(422)->assertJsonValidationErrors(['fee_type_id' => 'Pilih jenis biaya bulanan yang aktif.']);
});

test('daftar tagihan: saringan, ringkasan, lewat jatuh tempo; batalkan beralasan lalu bisa dibuat ulang', function () {
    $this->post(route('finance.invoices.generate'), fnvInput($this, '2026-07', '2026-10'))->assertSessionHasNoErrors();

    $this->get(route('finance.invoices.index', ['lembaga' => $this->smp->id, 'bulan' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/invoices')
            ->where('canManage', true)
            ->where('invoices.total', 4)
            ->where('invoices.sums', ['amount' => 375000, 'paid' => 0, 'outstanding' => 375000])
            ->where('invoices.rows.0.name', 'Ahmad Fauzi')
            ->where('invoices.rows.0.overdue', true)
            ->where('invoices.rows.1.name', 'Citra Lestari')
            ->where('invoices.rows.1.status', 'LUNAS')
            ->where('invoices.rows.1.overdue', false));

    $this->get(route('finance.invoices.index', ['lembaga' => $this->smp->id, 'q' => 'dewi', 'status' => 'TERBUKA']))
        ->assertInertia(fn (Assert $page) => $page->where('invoices.total', 4)->where('invoices.rows.0.description', 'SPP — Juli 2026'));

    $invoice = (string) DB::table('fn_invoices')->where('enrollment_id', fnvEnrollment('Dewi Sartika'))->where('period', '2026-10-01')->value('id');
    $this->post(route('finance.invoices.void', ['invoice' => $invoice]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('finance.invoices.void', ['invoice' => $invoice]), ['reason' => 'Salah tarif'])->assertSessionHasNoErrors();
    $this->post(route('finance.invoices.void', ['invoice' => $invoice]), ['reason' => 'Lagi'])->assertSessionHasErrors(['form' => 'Tagihan ini sudah dibatalkan.']);

    expect(DB::table('fn_invoices')->where('id', $invoice)->value('status'))->toBe('BATAL')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICE_VOIDED)->sole()->changes, true)['alasan'])->toBe('Salah tarif');

    // Tagihan yang dibatalkan tidak menghalangi tagihan baru untuk bulan yang sama.
    $this->post(route('finance.invoices.generate'), fnvInput($this, '2026-10', '2026-10'))->assertSessionHasNoErrors();
    expect(DB::table('fn_invoices')->where('enrollment_id', fnvEnrollment('Dewi Sartika'))->where('period', '2026-10-01')->count())->toBe(2);

    // Tagihan yang sudah ada pembayarannya (FN-3) tidak bisa dibatalkan.
    $paid = (string) DB::table('fn_invoices')->where('enrollment_id', fnvEnrollment('Ahmad Fauzi'))->where('period', '2026-07-01')->value('id');
    DB::table('fn_invoices')->where('id', $paid)->update(['paid_amount' => 10000]);
    $this->post(route('finance.invoices.void', ['invoice' => $paid]), ['reason' => 'Coba'])
        ->assertSessionHasErrors(['form' => 'Tagihan ini sudah ada pembayarannya. Batalkan pembayarannya dulu.']);
});

test('hak akses: Kepala Lembaga hanya melihat; Bendahara lembaga lain 404; tanpa izin 403; jatuh tempo hanya pengatur keuangan', function () {
    $this->post(route('finance.invoices.generate'), fnvInput($this, '2026-07', '2026-07'))->assertSessionHasNoErrors();
    $invoice = (string) DB::table('fn_invoices')->value('id');

    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($kepala)->get(route('finance.invoices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('invoices.total', 4));
    $this->actingAs($kepala)->post(route('finance.invoices.generate'), fnvInput($this, '2026-08', '2026-08'))->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.invoices.void', ['invoice' => $invoice]), ['reason' => 'X'])->assertForbidden();
    $this->actingAs($kepala)->post(route('finance.due-day.set'), ['target' => 'yayasan', 'day' => 5])->assertForbidden();

    $this->flushSession();
    $this->actingAs($bendaharaMts)->getJson(route('finance.invoices.preview', fnvInput($this, '2026-08', '2026-08')))->assertNotFound();
    $this->actingAs($bendaharaMts)->post(route('finance.invoices.void', ['invoice' => $invoice]), ['reason' => 'X'])->assertNotFound();

    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.invoices.index'))->assertForbidden();

    expect(DB::table('fn_invoices')->where('status', 'BATAL')->count())->toBe(0);
});
