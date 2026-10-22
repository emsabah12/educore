<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveType;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Saldo cuti, penyesuaian jatah, rekap .xlsx, ringkasan cuti di detail pegawai (PRD-001 §13.5, HR-3d-b).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai aktif di MA Unit 1; `admin.yayasan` = Admin SDM.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();
    $this->sick = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'SAKIT')->sole();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id,
        name: 'Guru', gender: Gender::Female, birthPlace: null, birthDate: null, phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
});

/**
 * @param  array<string, int|string>  $overrides
 */
function slcAdjust(mixed $test, array $overrides = []): TestResponse
{
    return $test->actingAs($test->admin)->post(route('hr.leave-balances.adjust', ['employee' => $test->employee]), array_merge([
        'leave_type_id' => $test->annual->id, 'year' => 2026, 'days' => 3, 'reason' => 'Lembur akhir tahun ajaran',
    ], $overrides));
}

/**
 * Semua lembar file .xlsx yang diunduh: nama lembar → baris.
 *
 * @return array<string, list<list<mixed>>>
 */
function slcSheets(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    @unlink($path);

    return $sheets;
}

test('penyesuaian jatah: menambah sisa, tercatat di riwayat & audit tanpa alasan; aturan tahun, jenis, nol, dan sisa negatif', function () {
    $this->actingAs($this->admin)->get(route('hr.leave-balances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/leaves/balances')
            ->where('filters.tahun', 2026)
            ->where('canAdjust', true)
            ->has('employees.data', 1)
            ->where('employees.data.0.balances.0', [
                'type_id' => $this->annual->id, 'name' => 'Cuti tahunan', 'quota' => 12, 'adjustment' => 0, 'used' => 0, 'pending' => 0, 'remaining' => 12,
            ]),
        );

    slcAdjust($this)->assertSessionHasNoErrors();

    $audit = json_decode((string) DB::table('audit_logs')->where('action', HrAuditAction::LEAVE_ADJUSTED)->sole()->changes, true);
    // Kolom jsonb tidak menjaga urutan key: bandingkan isi, bukan urutan.
    expect($audit)->toEqual(['jenis' => 'Cuti tahunan', 'tahun' => 2026, 'hari' => 3]);

    $this->actingAs($this->admin)->get(route('hr.leave-balances.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.data.0.balances.0.adjustment', 3)
            ->where('employees.data.0.balances.0.remaining', 15)
            ->where('employees.data.0.adjustments.0.days', 3)
            ->where('employees.data.0.adjustments.0.reason', 'Lembur akhir tahun ajaran')
            ->where('employees.data.0.adjustments.0.by', 'Admin Yayasan'),
        );

    slcAdjust($this, ['days' => 0])->assertSessionHasErrors(['days' => 'Isi jumlah hari selain 0, paling banyak 366 (pakai tanda minus untuk mengurangi).']);
    slcAdjust($this, ['year' => 2024])->assertSessionHasErrors(['year' => 'Penyesuaian hanya untuk tahun 2025 atau 2026.']);
    slcAdjust($this, ['leave_type_id' => $this->sick->id])->assertSessionHasErrors(['leave_type_id' => 'Pilih jenis cuti yang berjatah.']);
    slcAdjust($this, ['days' => -16])->assertSessionHasErrors(['days' => 'Sisa jatah Cuti tahunan tahun 2026 tinggal 15 hari; pengurangan paling banyak 15 hari.']);
    slcAdjust($this, ['reason' => ''])->assertSessionHasErrors(['reason' => 'Tulis alasan penyesuaian.']);
    slcAdjust($this, ['year' => 2025, 'days' => -2, 'reason' => 'Koreksi tahun lalu'])->assertSessionHasNoErrors();

    expect(DB::table('hr_leave_adjustments')->count())->toBe(2);

    // Pegawai melihat penyesuaian di Cuti saya; pengajuan memakai sisa baru (12 + 3 = 15).
    // 2–19 Nov tanpa Ahad 8 & 15 Nov = 16 hari kerja.
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-leave.index'))
        ->assertInertia(fn (Assert $page) => $page->where('balances.0.adjustment', 3)->where('balances.0.remaining', 15));
    $this->actingAs($this->guru)->post(route('hr.my-leave.store'), [
        'leave_type_id' => $this->annual->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-19', 'reason' => 'Umrah',
    ])->assertSessionHasErrors(['end_date' => 'Sisa jatah Cuti tahunan tahun 2026 tinggal 15 hari (sudah dikurangi pengajuan yang masih menunggu); pengajuan ini 16 hari.']);
});

test('saringan halaman saldo; hanya Admin SDM di tingkat Yayasan; Admin tidak menyesuaikan jatahnya sendiri', function () {
    $index = fn (array $query = []) => $this->actingAs($this->admin)->get(route('hr.leave-balances.index', $query));

    $index(['lembaga' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id])->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
    $index(['lembaga' => CoreFixtures::node($this->tenant, 'U3-SMK')->id])->assertInertia(fn (Assert $page) => $page->where('employees.data', []));
    $index(['pegawai' => $this->employee])->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('filters.pegawai', $this->employee));
    $index(['cari' => 'tidak-ada'])->assertInertia(fn (Assert $page) => $page->where('employees.data', []));
    $index(['tahun' => '1999', 'jenis' => 'x'])->assertInertia(fn (Assert $page) => $page->where('filters.tahun', 2026)->where('filters.jenis', ''));
    $index(['tahun' => '2022'])->assertInertia(fn (Assert $page) => $page->where('filters.tahun', 2022)->where('canAdjust', false));

    $this->flushSession();
    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.leave-balances.index'))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.leave-balances.export'))->assertForbidden();
    $this->actingAs($kepala)->post(route('hr.leave-balances.adjust', ['employee' => $this->employee]), [])->assertForbidden();

    // Admin yang juga pegawai.
    $this->flushSession();
    $own = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->admin->person_id)->sole()->id,
        name: 'Admin', gender: Gender::Male, birthPlace: null, birthDate: null, phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: (string) CatalogKind::EmploymentType->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'TETAP')->value('id'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($this->tenant, 'U1-MA')->id,
        positionId: (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', 'GURU')->value('id'),
    ))->id;

    $this->actingAs($this->admin)->post(route('hr.leave-balances.adjust', ['employee' => $own]), [
        'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => 5, 'reason' => 'Coba',
    ])->assertSessionHasErrors(['form' => 'Anda tidak bisa menyesuaikan jatah cuti Anda sendiri. Minta Admin SDM lain.']);
});

test('rekap .xlsx: lembar Daftar cuti (hanya disetujui, per periode) dan lembar Saldo', function () {
    // Disetujui 2–4 Nov (3 hari, dicatat admin), menunggu 26–30 Okt (tidak masuk daftar), penyesuaian +2.
    $this->actingAs($this->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $this->employee, 'leave_type_id' => $this->annual->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'reason' => 'Melapor langsung',
    ])->assertSessionHasNoErrors();
    slcAdjust($this, ['days' => 2])->assertSessionHasNoErrors();

    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->post(route('hr.my-leave.store'), [
        'leave_type_id' => $this->annual->id, 'start_date' => '2026-10-26', 'end_date' => '2026-10-30', 'reason' => 'Acara keluarga',
    ])->assertSessionHasNoErrors();
    $this->flushSession();

    $response = $this->actingAs($this->admin)->get(route('hr.leave-balances.export', ['tahun' => 2026]))
        ->assertOk()
        ->assertDownload('rekap-cuti-2026.xlsx');
    $sheets = slcSheets($response);
    $organization = CoreFixtures::node($this->tenant, 'U1-MA')->name;

    expect(array_keys($sheets))->toBe(['Daftar cuti', 'Saldo 2026'])
        ->and($sheets['Daftar cuti'][0])->toBe(['No', 'Nama', 'Nomor induk', 'Lembaga', 'Jenis', 'Mulai', 'Selesai', 'Hari kerja', 'Dicatat oleh'])
        ->and($sheets['Daftar cuti'])->toHaveCount(2)
        ->and([$sheets['Daftar cuti'][1][1], $sheets['Daftar cuti'][1][3], $sheets['Daftar cuti'][1][4], $sheets['Daftar cuti'][1][5], $sheets['Daftar cuti'][1][6], (int) $sheets['Daftar cuti'][1][7], $sheets['Daftar cuti'][1][8]])
        ->toBe(['Guru MA & MDA', $organization, 'Cuti tahunan', '2026-11-02', '2026-11-04', 3, 'Admin Yayasan'])
        ->and($sheets['Saldo 2026'][0])->toBe(['No', 'Nama', 'Nomor induk', 'Lembaga', 'Jenis', 'Jatah', 'Penyesuaian', 'Terpakai', 'Menunggu', 'Sisa'])
        ->and($sheets['Saldo 2026'])->toHaveCount(2)
        ->and([$sheets['Saldo 2026'][1][1], $sheets['Saldo 2026'][1][4]])->toBe(['Guru MA & MDA', 'Cuti tahunan'])
        ->and(array_map('intval', array_slice($sheets['Saldo 2026'][1], 5, 5)))->toBe([12, 2, 3, 5, 11])
        ->and(json_encode($sheets))->not->toContain('Melapor')->not->toContain('Acara keluarga');

    // Oktober: tidak ada cuti disetujui.
    $october = slcSheets($this->actingAs($this->admin)->get(route('hr.leave-balances.export', ['tahun' => 2026, 'bulan' => 10]))
        ->assertDownload('rekap-cuti-2026-10.xlsx'));
    expect($october['Daftar cuti'])->toHaveCount(1);

    // Saringan lembaga di luar penempatan: kedua lembar kosong.
    $smk = slcSheets($this->actingAs($this->admin)->get(route('hr.leave-balances.export', ['tahun' => 2026, 'lembaga' => CoreFixtures::node($this->tenant, 'U3-SMK')->id])));
    expect($smk['Daftar cuti'])->toHaveCount(1)->and($smk['Saldo 2026'])->toHaveCount(1);
});

test('detail pegawai menampilkan ringkasan cuti tahun berjalan bagi yang boleh melihat data pribadi', function () {
    $this->actingAs($this->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $this->employee, 'leave_type_id' => $this->annual->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'reason' => 'Melapor langsung',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('hr.employees.show', ['employee' => $this->employee]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageLeave', true)
            ->where('leave.year', 2026)
            ->where('leave.balances.0.used', 3)
            ->where('leave.balances.0.remaining', 9)
            ->has('leave.recent', 1)
            ->where('leave.recent.0.status', 'DISETUJUI'),
        );
    $this->flushSession();

    // Kepala Unit 1 boleh melihat data pribadi pegawai di cakupannya, tetapi tidak mengelola cuti.
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.employees.show', ['employee' => $this->employee]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageLeave', false)->where('leave.balances.0.remaining', 9));
});
