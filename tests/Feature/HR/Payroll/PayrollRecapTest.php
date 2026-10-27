<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Payroll\EmployeePay;
use Modules\HR\Application\Payroll\ManagePayComponents;
use Modules\HR\Application\Payroll\ManagePositionRates;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrPayComponentSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Rekap gaji bulanan, penyesuaian, kunci, .xlsx (PRD-001 §16.3, HR-5c-1). "Sekarang" Kamis 22 Okt 2026.
 *
 * Ahmad (Wakil Kepala, MA Unit 1): tunjangan struktural 1.000.000 (tarif jabatan), gaji pokok 2.500.000,
 *   uang transport 15.000 × 3 hari hadir, iuran BPJS 100.000, Alpa 1 × 50.000, penyesuaian + 200.000
 *   → pendapatan 3.745.000, potongan 150.000, bersih 3.595.000.
 * Budi (Guru, MA Unit 1): belum ada komponen.
 */

function rkgId(mixed $test, CatalogKind $kind, string $code): string
{
    return (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');
}

function rkgEmployee(mixed $test, string $name, string $position): string
{
    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: null, name: $name, gender: Gender::Male, birthPlace: null, birthDate: null,
        phone: null, address: null, employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: rkgId($test, CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: $test->ma, positionId: rkgId($test, CatalogKind::Position, $position),
    ))->id;
}

function rkgAttendance(mixed $test, string $date, string $status): void
{
    DB::table('hr_attendances')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $test->tenant->id, 'employee_id' => $test->ahmad, 'organization_id' => $test->ma,
        'date' => $date, 'status' => $status, 'check_in' => $status === 'HADIR' ? '07:00:00' : null, 'check_in_source' => $status === 'HADIR' ? 'PETUGAS' : null,
        'late_minutes' => 0, 'early_minutes' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rkgComponent(string $code): string
{
    return (string) PayComponent::query()->withoutGlobalScope('tenant')->where('code', $code)->value('id');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 13:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrPayComponentSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $this->ahmad = rkgEmployee($this, 'Ahmad', 'WAKIL-KEPALA');
    $this->budi = rkgEmployee($this, 'Budi', 'GURU');

    $structural = app(ManagePayComponents::class)->create($this->tenant->id, 'TUNJ-STRUKTURAL', 'Tunjangan struktural', PayComponentKind::Earning, PayComponentBasis::PerPosition);
    app(ManagePositionRates::class)->set($this->tenant->id, $structural->id, rkgId($this, CatalogKind::Position, 'WAKIL-KEPALA'), null, '1000000');
    app(EmployeePay::class)->save($this->tenant->id, $this->ahmad, [rkgComponent('GAPOK') => '2500000', rkgComponent('TRANSPORT') => '15000', rkgComponent('BPJS') => '100000'], null);

    foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
        rkgAttendance($this, $date, 'HADIR');
    }

    rkgAttendance($this, '2026-10-06', 'ALPA');

    $this->actingAs($this->admin)
        ->post(route('hr.payroll.deduction'), ['target' => 'yayasan', 'absent' => '50000', 'late' => '0', 'early' => '0'])
        ->assertSessionHasNoErrors();
    $this->post(route('hr.payroll-recap.adjustments.store', ['month' => '2026-10']), [
        'employee_id' => $this->ahmad, 'kind' => 'PENDAPATAN', 'amount' => '200.000', 'note' => 'Lembur kegiatan',
    ])->assertSessionHasNoErrors();
});

test('rekap bulan berjalan dari data terkini: kolom per kelompok, penyesuaian, peringatan', function () {
    $this->get(route('hr.payroll-recap.index', ['bulan' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/payroll/recap')
            ->where('lock', null)
            ->where('until', '2026-10-22')
            ->has('rows', 2)
            ->where('rows.0.name', 'Ahmad')
            ->where('rows.0.columns', [
                'position' => 1000000, 'fixed' => 2500000, 'present_day' => 45000, 'teaching' => 0,
                'adjustment_earning' => 200000, 'fixed_deduction' => 100000, 'attendance_deduction' => 50000, 'adjustment_deduction' => 0,
            ])
            ->where('rows.0.earning', 3745000)
            ->where('rows.0.deduction', 150000)
            ->where('rows.0.net', 3595000)
            ->where('rows.0.adjustments.0.note', 'Lembur kegiatan')
            ->where('rows.1.name', 'Budi')
            ->where('rows.1.warnings', fn ($warnings) => collect($warnings)->contains('Belum ada komponen gaji.'))
            ->where('totals.net', 3595000));
});

test('kunci bulan: snapshot tidak berubah walau nominal diubah; penyesuaian terkunci; buka kunci beralasan', function () {
    $this->post(route('hr.payroll-recap.lock', ['month' => '2026-10']))->assertSessionHasNoErrors();
    $this->post(route('hr.payroll-recap.lock', ['month' => '2026-10']))->assertSessionHasErrors(['form' => 'Oktober 2026 sudah dikunci.']);

    app(EmployeePay::class)->save($this->tenant->id, $this->ahmad, [rkgComponent('GAPOK') => '3000000'], null);

    $this->get(route('hr.payroll-recap.index', ['bulan' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lock.employee_count', 2)
            ->where('lock.until', '2026-10-22')
            ->where('rows.0.columns.fixed', 2500000)
            ->where('rows.0.net', 3595000));

    $this->post(route('hr.payroll-recap.adjustments.store', ['month' => '2026-10']), [
        'employee_id' => $this->ahmad, 'kind' => 'POTONGAN', 'amount' => '1', 'note' => 'X',
    ])->assertSessionHasErrors('form');

    $this->post(route('hr.payroll-recap.unlock', ['month' => '2026-10']), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('hr.payroll-recap.unlock', ['month' => '2026-10']), ['reason' => 'Koreksi gaji pokok'])->assertSessionHasNoErrors();

    $this->get(route('hr.payroll-recap.index', ['bulan' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('lock', null)->where('rows.0.columns.fixed', 3000000));

    $audit = DB::table('audit_logs')->whereIn('action', [HrAuditAction::PAYROLL_LOCKED, HrAuditAction::PAYROLL_UNLOCKED, HrAuditAction::PAYROLL_ADJUSTED])->pluck('changes')->implode(' ');
    expect(DB::table('audit_logs')->where('action', HrAuditAction::PAYROLL_UNLOCKED)->count())->toBe(1)
        ->and($audit)->toContain('Koreksi gaji pokok')->not->toContain('200000')->not->toContain('Lembur')
        ->and(DB::table('hr_payslips')->count())->toBe(0);
});

test('penyesuaian: hapus, aturan nominal & keterangan; bulan masa depan ditolak', function () {
    $adjustment = (string) DB::table('hr_payroll_adjustments')->value('id');
    $url = route('hr.payroll-recap.adjustments.store', ['month' => '2026-10']);

    $this->post($url, ['employee_id' => $this->ahmad, 'kind' => 'POTONGAN', 'amount' => '0', 'note' => 'Kasbon'])->assertSessionHasErrors('amount');
    $this->post($url, ['employee_id' => $this->ahmad, 'kind' => 'POTONGAN', 'amount' => '100000', 'note' => '   '])->assertSessionHasErrors('note');
    $this->post(route('hr.payroll-recap.adjustments.store', ['month' => '2026-11']), ['employee_id' => $this->ahmad, 'kind' => 'POTONGAN', 'amount' => '1', 'note' => 'X'])
        ->assertSessionHasErrors(['form' => 'Bulan tidak valid atau masih di masa depan.']);

    $this->delete(route('hr.payroll-recap.adjustments.destroy', ['month' => '2026-10', 'adjustment' => $adjustment]))->assertSessionHasNoErrors();
    expect(DB::table('hr_payroll_adjustments')->count())->toBe(0);
});

test('unduh .xlsx: lembar Rekap (dengan Jumlah) dan Rincian per komponen', function () {
    $response = $this->get(route('hr.payroll-recap.export', ['bulan' => '2026-10']));
    $response->assertOk()->assertDownload('rekap-gaji-2026-10.xlsx');

    $reader = new XlsxReader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }
    }

    $reader->close();

    expect(array_keys($sheets))->toBe(['Rekap', 'Rincian'])
        ->and($sheets['Rekap'][0][0])->toContain('BELUM DIKUNCI')
        ->and(end($sheets['Rekap']))->toBe(['Jumlah', '', '', 1000000, 2500000, 45000, 0, 200000, 100000, 50000, 0, 3745000, 150000, 3595000])
        ->and($sheets['Rincian'])->toContain(['Ahmad', 'Per hari hadir', 'Uang transport', 'Rp 15.000 × 3 hari', 'Pendapatan', 45000]);
});

test('hanya Admin SDM / Admin Yayasan', function () {
    $this->flushSession();
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepala)->get(route('hr.payroll-recap.index'))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.payroll-recap.lock', ['month' => '2026-10']))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.payroll-recap.export'))->assertForbidden();
});
