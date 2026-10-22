<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicCalendarSeeder;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Holiday;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\DevAccounts;

/*
 * Kalender hari libur (PRD-002 §18 P4): hari libur mingguan per kalender dan hari libur per tahun
 * ajaran. Data uji: Kalender Yayasan dengan tahun ajaran 2026/2027 (1 Jul 2026 – 30 Jun 2027).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->default = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_default', true)->sole();

    $this->actingAs($this->admin)->post(route('academic.years.store', ['calendar' => $this->default->id]), [
        'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
        'terms' => [
            ['name' => 'Semester Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31'],
            ['name' => 'Semester Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30'],
        ],
    ])->assertSessionHasNoErrors();

    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $this->default->id)->sole();
});

/**
 * @return array<string, mixed>
 */
function hlbAudit(string $action): array
{
    return json_decode((string) DB::table('audit_logs')->where('action', $action)->sole()->changes, true);
}

test('libur mingguan bawaan Ahad; Admin Yayasan mengubahnya; tercatat di audit', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.weekly_off_days', [7])
            ->where('dayOptions.0', ['value' => '1', 'label' => 'Senin'])
            ->where('selected.years.0.holidays', []),
        );

    $url = route('academic.calendar.weekly-off', ['calendar' => $this->default->id]);

    $this->actingAs($this->admin)->post($url, ['weekly_off_days' => ['7', '5']])->assertSessionHasNoErrors();

    expect($this->default->refresh()->weekly_off_days)->toBe([5, 7])
        ->and(hlbAudit(AcademicAuditAction::WEEKLY_OFF_DAYS_CHANGED))->toEqual([
            'kalender' => $this->default->name, 'sebelum' => ['Ahad'], 'sesudah' => ['Jumat', 'Ahad'],
        ]);

    // Tanpa centang = tanpa libur mingguan; tujuh hari ditolak; hari asal-asalan ditolak.
    $this->actingAs($this->admin)->post($url, [])->assertSessionHasNoErrors();
    expect($this->default->refresh()->weekly_off_days)->toBe([]);

    $this->actingAs($this->admin)->post($url, ['weekly_off_days' => [1, 2, 3, 4, 5, 6, 7]])
        ->assertSessionHasErrors(['weekly_off_days' => 'Sisakan minimal satu hari sekolah dalam sepekan.']);
    $this->actingAs($this->admin)->post($url, ['weekly_off_days' => [8]])
        ->assertSessionHasErrors(['weekly_off_days.0' => 'Pilih hari dari daftar.']);

    $this->actingAs(DevAccounts::user('kepala.unit1'))->post($url, ['weekly_off_days' => [6, 7]])->assertForbidden();
});

test('tambah, ubah, hapus hari libur; harus di dalam tahun ajaran; tercatat di audit', function () {
    $store = route('academic.holidays.store', ['year' => $this->year->id]);

    $this->actingAs($this->admin)->post($store, ['name' => '  Libur   semester ', 'start_date' => '2026-12-21', 'end_date' => '2027-01-02'])
        ->assertSessionHasNoErrors();

    $holiday = Holiday::query()->withoutGlobalScope('tenant')->sole();

    expect($holiday->name)->toBe('Libur semester')
        ->and(hlbAudit(AcademicAuditAction::HOLIDAY_ADDED))->toEqual([
            'kalender' => $this->default->name, 'tahun_ajaran' => '2026/2027', 'nama' => 'Libur semester', 'mulai' => '2026-12-21', 'selesai' => '2027-01-02',
        ]);

    $this->actingAs($this->admin)
        ->get(route('academic.calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('selected.years.0.holidays', [
            ['id' => $holiday->id, 'name' => 'Libur semester', 'start_date' => '2026-12-21', 'end_date' => '2027-01-02', 'days' => 13],
        ]));

    // Di luar tahun ajaran / selesai sebelum mulai → ditolak.
    $this->actingAs($this->admin)->post($store, ['name' => 'Libur Juli', 'start_date' => '2026-06-29', 'end_date' => '2026-07-03'])
        ->assertSessionHasErrors(['start_date' => 'Hari libur harus di dalam tahun ajaran 2026/2027 (2026-07-01 s.d. 2027-06-30).']);
    $this->actingAs($this->admin)->post($store, ['name' => 'Terbalik', 'start_date' => '2026-08-17', 'end_date' => '2026-08-16'])
        ->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);

    $update = route('academic.holidays.update', ['holiday' => $holiday->id]);
    $this->actingAs($this->admin)->patch($update, ['name' => 'Libur semester', 'start_date' => '2026-12-21', 'end_date' => '2027-01-03'])
        ->assertSessionHasNoErrors();

    expect($holiday->refresh()->end_date->toDateString())->toBe('2027-01-03')
        ->and(hlbAudit(AcademicAuditAction::HOLIDAY_UPDATED))->toEqual([
            'kalender' => $this->default->name, 'tahun_ajaran' => '2026/2027', 'selesai' => ['2027-01-02', '2027-01-03'],
        ]);

    $this->actingAs($this->admin)->delete(route('academic.holidays.destroy', ['holiday' => $holiday->id]))->assertSessionHasNoErrors();

    expect(Holiday::query()->withoutGlobalScope('tenant')->count())->toBe(0)
        ->and(hlbAudit(AcademicAuditAction::HOLIDAY_REMOVED)['nama'])->toBe('Libur semester');

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post($store, ['name' => 'Libur', 'start_date' => '2026-08-17', 'end_date' => '2026-08-17'])
        ->assertForbidden();
});

test('tanggal tahun ajaran tidak bisa diubah sampai hari liburnya tertinggal di luar', function () {
    $this->actingAs($this->admin)
        ->post(route('academic.holidays.store', ['year' => $this->year->id]), ['name' => 'Libur awal tahun', 'start_date' => '2026-07-01', 'end_date' => '2026-07-03'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->patch(route('academic.years.update', ['year' => $this->year->id]), [
        'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-30',
        'terms' => [
            ['name' => 'Semester Ganjil', 'start_date' => '2026-07-13', 'end_date' => '2026-12-31'],
            ['name' => 'Semester Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30'],
        ],
    ])->assertSessionHasErrors(['start_date' => 'Hari libur Libur awal tahun berada di luar tanggal tahun ajaran yang baru. Ubah atau hapus hari liburnya dulu.']);

    expect($this->year->refresh()->start_date->toDateString())->toBe('2026-07-01');
});
