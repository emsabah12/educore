<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 * Tagihan sekali bayar & batal otomatis saat siswa keluar (PRD-003 §4, OD-FN-07, FN-2b).
 * Data sama dengan InvoicesTest: "hari ini" 29 Okt 2026, SMP Unit 3 —
 *   Ahmad (7-A), Citra (9-A), Dewi (belum masuk kelas), Eko (7-A, masuk 5 Okt), Fajar (7-A, keluar 31 Agt).
 * Seragam (sekali bayar): semua tingkat Rp 300.000, tingkat 9 Rp 350.000; Ahmad potongan Seragam 100%.
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
            'organization_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'enrollment_id' => fnoEnrollment($name),
            'fee_type_id' => $this->spp->id, 'kind' => $kind, 'value' => $value, 'reason' => 'Beasiswa',
        ])->assertSessionHasNoErrors();
    }

    $this->post(route('finance.due-day.set'), ['target' => 'lembaga', 'organization_id' => $this->smp->id, 'day' => 15])->assertSessionHasNoErrors();
});

function fnoEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')->join('ac_students as s', 's.id', '=', 'e.student_id')->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')->where('p.name', $name)->value('e.id');
}

/**
 * @return array<string, string>
 */
function fnoInput(mixed $test, string $from, string $to): array
{
    return ['organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'fee_type_id' => $test->spp->id, 'from' => $from, 'to' => $to];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function fnoOnce(mixed $test, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'fee_type_id' => $test->seragam->id,
        'target' => 'lembaga', 'due_date' => '2026-11-15',
    ], $overrides);
}

function fnoSeragam(mixed $test): void
{
    $test->seragam = app(ManageFeeTypes::class)->create($test->tenant->id, 'SERAGAM', 'Seragam', FeeKind::Once);
    app(ManageFeeRates::class)->set($test->tenant->id, $test->seragam->id, $test->smp->id, $test->year->id, null, '300000', null);
    app(ManageFeeRates::class)->set($test->tenant->id, $test->seragam->id, $test->smp->id, $test->year->id, 9, '350000', null);
    $test->post(route('finance.discounts.store'), [
        'organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'enrollment_id' => fnoEnrollment('Ahmad Fauzi'),
        'fee_type_id' => $test->seragam->id, 'kind' => 'PERSEN', 'value' => '100', 'reason' => 'Beasiswa',
    ])->assertSessionHasNoErrors();
}

test('sekali bayar: per kelas, lalu seluruh lembaga (yang sudah ada dilewati), lalu satu siswa; siswa keluar tidak ditagih', function () {
    fnoSeragam($this);
    $class = (string) SchoolClass::query()->withoutGlobalScope('tenant')->where('name', '7-A')->value('id');

    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['target' => 'kelas', 'class_id' => $class])))
        ->assertOk()
        ->assertJsonPath('summary.students', 2)
        ->assertJsonPath('summary.created', 2)
        ->assertJsonPath('summary.free', 1)
        ->assertJsonPath('summary.total', 300000)
        ->assertJsonPath('summary.months', ['2026/2027']);
    $this->post(route('finance.invoices.generate-once'), fnoOnce($this, ['target' => 'kelas', 'class_id' => $class]))->assertSessionHasNoErrors();

    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this)))
        ->assertJsonPath('summary.students', 4)
        ->assertJsonPath('summary.existing', 2)
        ->assertJsonPath('summary.created', 2)
        ->assertJsonPath('summary.total', 650000);
    $this->post(route('finance.invoices.generate-once'), fnoOnce($this))->assertSessionHasNoErrors();

    $rows = DB::table('fn_invoices')->orderBy('amount')->get(['description', 'amount', 'status', 'due_date', 'period']);
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('amount')->all())->toBe([0, 300000, 300000, 350000])
        ->and($rows[0]->status)->toBe('LUNAS')
        ->and($rows[1]->description)->toBe('Seragam — 2026/2027')
        ->and(substr((string) $rows[1]->due_date, 0, 10))->toBe('2026-11-15')
        ->and($rows->pluck('period')->filter()->all())->toBe([]);

    // Satu siswa: sudah punya → dilewati; Fajar sudah keluar → ditolak.
    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['target' => 'siswa', 'enrollment_id' => fnoEnrollment('Ahmad Fauzi')])))
        ->assertJsonPath('summary.created', 0)->assertJsonPath('summary.existing', 1);
    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['target' => 'siswa', 'enrollment_id' => fnoEnrollment('Fajar Nugraha')])))
        ->assertStatus(422)->assertJsonValidationErrors(['enrollment_id' => 'Pilih siswa yang terdaftar di lembaga ini pada tahun ajaran itu.']);
});

test('sekali bayar: isian salah ditolak', function () {
    fnoSeragam($this);

    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['fee_type_id' => $this->spp->id])))
        ->assertStatus(422)->assertJsonValidationErrors(['fee_type_id' => 'Pilih jenis biaya sekali bayar yang aktif.']);
    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['due_date' => '2026-02-30'])))->assertStatus(422)->assertJsonValidationErrors('due_date');
    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['target' => 'kelas'])))->assertStatus(422)->assertJsonValidationErrors('class_id');
    $this->getJson(route('finance.invoices.preview-once', fnoOnce($this, ['target' => 'kelas', 'class_id' => fnoEnrollment('Ahmad Fauzi')])))
        ->assertStatus(422)->assertJsonValidationErrors(['class_id' => 'Pilih kelas aktif lembaga ini di tahun ajaran itu.']);

    expect(DB::table('fn_invoices')->count())->toBe(0);
});

test('siswa keluar: tagihan bulanan sesudah bulan keluarnya yang belum dibayar dibatalkan otomatis; sekali bayar & bulan keluarnya tetap', function () {
    fnoSeragam($this);
    $this->post(route('finance.invoices.generate'), fnoInput($this, '2026-07', '2026-12'))->assertSessionHasNoErrors();
    $this->post(route('finance.invoices.generate-once'), fnoOnce($this, ['target' => 'siswa', 'enrollment_id' => fnoEnrollment('Dewi Sartika')]))->assertSessionHasNoErrors();

    $dewi = fnoEnrollment('Dewi Sartika');
    DB::table('fn_invoices')->where('enrollment_id', $dewi)->where('period', '2026-12-01')->update(['paid_amount' => 10000]);
    $student = (string) DB::table('ac_enrollments')->where('id', $dewi)->value('student_id');

    $this->post(route('academic.enrollments.close', ['student' => $student, 'enrollment' => $dewi]), ['end_date' => '2026-10-20', 'end_reason' => 'PINDAH'])
        ->assertSessionHasNoErrors();

    $statuses = DB::table('fn_invoices')->where('enrollment_id', $dewi)->orderByRaw('period NULLS LAST')->get(['period', 'status', 'void_reason'])
        ->map(fn ($row): string => ($row->period === null ? 'sekali' : substr((string) $row->period, 0, 7)).' '.$row->status)->all();

    expect($statuses)->toBe(['2026-07 TERBUKA', '2026-08 TERBUKA', '2026-09 TERBUKA', '2026-10 TERBUKA', '2026-11 BATAL', '2026-12 TERBUKA', 'sekali TERBUKA'])
        ->and(DB::table('fn_invoices')->where('enrollment_id', $dewi)->where('status', 'BATAL')->value('void_reason'))->toBe('Siswa keluar (dibatalkan otomatis)')
        ->and(json_decode((string) DB::table('audit_logs')->where('action', FinanceAuditAction::INVOICES_VOIDED_ON_EXIT)->sole()->changes, true))
        ->toEqual(['tanggal_keluar' => '2026-10-20', 'jumlah' => 1]);

    // Siswa lain tidak tersentuh.
    expect(DB::table('fn_invoices')->where('enrollment_id', '!=', $dewi)->where('status', 'BATAL')->count())->toBe(0);
});

test('hak akses sekali bayar: Kepala Lembaga 403, Bendahara lembaga lain 404', function () {
    fnoSeragam($this);
    CoreFixtures::syncAccess();
    $kepala = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($kepala, $this->tenant), $this->smp), 'kepala-lembaga');
    $bendaharaMts = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendaharaMts, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MTS')), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($kepala)->post(route('finance.invoices.generate-once'), fnoOnce($this))->assertForbidden();
    $this->flushSession();
    $this->actingAs($bendaharaMts)->getJson(route('finance.invoices.preview-once', fnoOnce($this)))->assertNotFound();

    expect(DB::table('fn_invoices')->count())->toBe(0);
});
