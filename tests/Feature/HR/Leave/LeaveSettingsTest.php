<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Leave\WorkCalendar;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Leave\Holiday;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti, libur mingguan kalender kerja, hari libur.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

function ctiType(string $code): LeaveType
{
    return LeaveType::query()->withoutGlobalScope('tenant')->where('code', $code)->sole();
}

/**
 * @return array<string, mixed>
 */
function ctiAudit(string $action, ?string $subjectId = null): array
{
    // Seeder jenis cuti juga mencatat audit; saring per subjek bila perlu.
    $query = DB::table('audit_logs')->where('action', $action);

    if ($subjectId !== null) {
        $query->where('subject_id', $subjectId);
    }

    return json_decode((string) $query->sole()->changes, true);
}

test('isi awal jenis cuti (aman diulang); halaman hanya untuk pengelola cuti tingkat Yayasan', function () {
    $this->seed(HrLeaveTypeSeeder::class);

    expect(LeaveType::query()->withoutGlobalScope('tenant')->count())->toBe(4)
        ->and(ctiType('CUTI-TAHUNAN')->only(['name', 'has_quota', 'annual_quota', 'max_days']))
        ->toBe(['name' => 'Cuti tahunan', 'has_quota' => true, 'annual_quota' => 12, 'max_days' => null])
        ->and(ctiType('MELAHIRKAN')->max_days)->toBe(90);

    $this->actingAs($this->admin)
        ->get(route('hr.leave-settings.index', ['tahun' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/leave-settings')
            ->has('types', 4)
            ->where('year', 2026)
            ->where('holidays', [])
            ->where('weeklyOff.rules', [])
            ->where('weeklyOff.usage', fn ($usage) => collect($usage)->every(fn (array $row): bool => $row['days'] === 'Ahad' && $row['source'] === 'Bawaan'))
            ->where('dayOptions.6', ['value' => '7', 'label' => 'Ahad']),
        );

    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.leave-settings.index'))->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('hr.leave-types.store'), ['code' => 'HAJI', 'name' => 'Cuti haji'])
        ->assertForbidden();
});

test('jenis cuti: tambah (kode huruf besar, berjatah wajib jatah), ubah, nonaktifkan, aktifkan; tercatat di audit', function () {
    $store = fn (array $data) => $this->actingAs($this->admin)->post(route('hr.leave-types.store'), $data);

    $store(['code' => 'haji', 'name' => 'Cuti haji', 'has_quota' => '1'])
        ->assertSessionHasErrors(['annual_quota' => 'Isi jatah per tahun 1–366 hari.']);
    $store(['code' => 'sakit', 'name' => 'Sakit lagi'])
        ->assertSessionHasErrors(['code' => 'Kode SAKIT sudah dipakai jenis cuti lain.']);
    $store(['code' => 'haji', 'name' => '  Cuti   haji ', 'max_days' => '40', 'annual_quota' => '5'])->assertSessionHasNoErrors();

    $haji = ctiType('HAJI');

    expect($haji->only(['name', 'has_quota', 'annual_quota', 'max_days']))->toBe(['name' => 'Cuti haji', 'has_quota' => false, 'annual_quota' => null, 'max_days' => 40])
        ->and(ctiAudit(HrAuditAction::LEAVE_TYPE_CREATED, $haji->id))->toEqual([
            'kode' => 'HAJI', 'nama' => 'Cuti haji', 'berjatah' => false, 'jatah_per_tahun' => null, 'maks_hari' => 40,
        ]);

    $annual = ctiType('CUTI-TAHUNAN');
    $this->actingAs($this->admin)
        ->patch(route('hr.leave-types.update', ['type' => $annual->id]), ['name' => 'Cuti tahunan', 'annual_quota' => '14', 'max_days' => '6'])
        ->assertSessionHasNoErrors();

    expect($annual->refresh()->annual_quota)->toBe(14)
        ->and(ctiAudit(HrAuditAction::LEAVE_TYPE_UPDATED))->toEqual(['kode' => 'CUTI-TAHUNAN', 'jatah_per_tahun' => [12, 14], 'maks_hari' => [null, 6]]);

    $this->actingAs($this->admin)
        ->patch(route('hr.leave-types.update', ['type' => $annual->id]), ['name' => 'Cuti tahunan', 'annual_quota' => ''])
        ->assertSessionHasErrors(['annual_quota' => 'Isi jatah per tahun 1–366 hari.']);

    $this->actingAs($this->admin)->post(route('hr.leave-types.deactivate', ['type' => $haji->id]))->assertSessionHasNoErrors();
    expect($haji->refresh()->is_active)->toBeFalse();
    $this->actingAs($this->admin)->post(route('hr.leave-types.deactivate', ['type' => $haji->id]))
        ->assertSessionHasErrors(['form' => 'Cuti haji sudah nonaktif.']);
    $this->actingAs($this->admin)->post(route('hr.leave-types.activate', ['type' => $haji->id]))->assertSessionHasNoErrors();
    expect($haji->refresh()->is_active)->toBeTrue();
});

test('kalender kerja: libur mingguan per jenjang + hari libur yayasan menentukan hari kerja', function () {
    $ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;

    $this->actingAs($this->admin)
        ->post(route('hr.weekly-off.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'weekly_off_days' => ['7', '5']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)
        ->post(route('hr.weekly-off.assign'), ['target' => 'jenjang', 'jenjang' => 'SMP', 'weekly_off_days' => ['1', '2', '3', '4', '5', '6', '7']])
        ->assertSessionHasErrors(['weekly_off_days' => 'Nilai aturan tidak valid: sisakan minimal satu hari kerja dalam sepekan.']);

    $holidays = route('hr.holidays.store');
    $this->actingAs($this->admin)->post($holidays, ['name' => 'Libur Maulid', 'start_date' => '2026-10-19', 'end_date' => '2026-10-20'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post($holidays, ['name' => 'Terbalik', 'start_date' => '2026-10-20', 'end_date' => '2026-10-19'])
        ->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);

    $calendar = app(WorkCalendar::class);

    // 12–25 Okt 2026 (Senin–Ahad): MA libur Jumat & Ahad; semua libur 19–20 Okt.
    expect($calendar->workingDays($this->tenant->id, $ma, '2026-10-12', '2026-10-25'))
        ->toBe(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-17', '2026-10-21', '2026-10-22', '2026-10-24'])
        ->and($calendar->workingDays($this->tenant->id, $smp, '2026-10-12', '2026-10-25'))->toHaveCount(10);

    $this->actingAs($this->admin)
        ->get(route('hr.leave-settings.index', ['tahun' => 2026]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeklyOff.rules', [['id' => (string) DB::table('scoped_settings')->where('key', 'hr.weekly_off_days')->value('id'), 'target' => 'Semua MA', 'days' => 'Jumat, Ahad', 'enforced' => false]])
            ->where('holidays.0.days', 2),
        );

    $holiday = Holiday::query()->withoutGlobalScope('tenant')->sole();
    $this->actingAs($this->admin)
        ->patch(route('hr.holidays.update', ['holiday' => $holiday->id]), ['name' => 'Libur Maulid Nabi', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19'])
        ->assertSessionHasNoErrors();

    expect(ctiAudit(HrAuditAction::HOLIDAY_UPDATED))->toEqual(['nama' => ['Libur Maulid', 'Libur Maulid Nabi'], 'selesai' => ['2026-10-20', '2026-10-19']])
        ->and($calendar->workingDays($this->tenant->id, $smp, '2026-10-20', '2026-10-20'))->toBe(['2026-10-20']);

    $this->actingAs($this->admin)->delete(route('hr.holidays.destroy', ['holiday' => $holiday->id]))->assertSessionHasNoErrors();

    expect(Holiday::query()->withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(ctiAudit(HrAuditAction::HOLIDAY_REMOVED)['nama'])->toBe('Libur Maulid Nabi');
});
