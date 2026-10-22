<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Hari efektif di presensi harian (PRD-002 §18 P4).
 *
 * "Hari ini" dikunci Kamis 22 Oktober 2026 (tahun ajaran 2026/2027). Libur mingguan bawaan Ahad
 * (4, 11, 18, 25 Okt); hari libur "Libur Maulid" Senin–Selasa 19–20 Okt. Kelas X-A (MA Unit 1)
 * berisi Ahmad; presensi harian diisi 20 (hari libur), 21, dan 22 Okt.
 *
 * Oktober: 31 hari − 4 Ahad − 2 hari libur = 25 hari efektif. Sampai 22 Okt: 22 − 3 Ahad − 2 = 17,
 * terisi 2 (21 & 22; tanggal 20 hari libur) → 15 belum diisi.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();

    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->sole();

    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->class->organization_id, 'start_date' => '2026-07-13',
        'entry_type' => 'BARU', 'class_id' => $this->class->id,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('academic.holidays.store', ['year' => $this->year->id]), ['name' => 'Libur Maulid', 'start_date' => '2026-10-19', 'end_date' => '2026-10-20'])
        ->assertSessionHasNoErrors();

    $this->ahmad = (string) DB::table('ac_enrollments')->value('id');
});

test('lembar presensi: peringatan di hari libur & libur mingguan; tetap bisa disimpan', function () {
    $sheet = fn (string $date) => $this->actingAs($this->admin)
        ->get(route('academic.attendance.show', ['class' => $this->class->id, 'tanggal' => $date]))
        ->assertOk();

    $sheet('2026-10-20')->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-20')->where('holiday', 'Libur Maulid'));
    $sheet('2026-10-18')->assertInertia(fn (Assert $page) => $page->where('holiday', 'Ahad (libur mingguan)'));
    $sheet('2026-10-21')->assertInertia(fn (Assert $page) => $page->where('holiday', null));

    $this->actingAs($this->admin)
        ->post(route('academic.attendance.store', ['class' => $this->class->id]), ['date' => '2026-10-20', 'attendance' => [$this->ahmad => ['status' => 'H']]])
        ->assertSessionHasNoErrors();

    expect(DB::table('ac_attendance_sessions')->where('date', '2026-10-20')->exists())->toBeTrue();
});

test('rekap harian: hari efektif periode, s.d. hari ini, dan yang belum diisi (hari libur yang diisi tidak dihitung)', function () {
    foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
        $this->actingAs($this->admin)
            ->post(route('academic.attendance.store', ['class' => $this->class->id]), ['date' => $date, 'attendance' => [$this->ahmad => ['status' => 'H']]])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => $this->class->id, 'periode' => 'bulan-2026-10', 'jenis' => 'harian']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recap.days', 3)
            ->where('effective', [
                'days' => 25,
                'due' => 17,
                'unfilled' => 15,
                'unfilled_dates' => [
                    '2026-10-17', '2026-10-16', '2026-10-15', '2026-10-14', '2026-10-13', '2026-10-12', '2026-10-10', '2026-10-09',
                    '2026-10-08', '2026-10-07', '2026-10-06', '2026-10-05', '2026-10-03', '2026-10-02', '2026-10-01',
                ],
            ])
            ->where('fill', ['url' => "/kelas/{$this->class->id}/presensi", 'min' => '2026-07-01', 'max' => '2026-10-22']),
        );

    // Rekap per pertemuan tidak memakai hari efektif.
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => $this->class->id, 'periode' => 'bulan-2026-10', 'jenis' => 'pertemuan']))
        ->assertInertia(fn (Assert $page) => $page->where('effective', null)->where('fill', null));
});

test('libur mingguan kalender ikut menentukan hari efektif', function () {
    $calendarId = $this->year->calendar_id;

    $this->actingAs($this->admin)
        ->post(route('academic.calendar.weekly-off', ['calendar' => $calendarId]), ['weekly_off_days' => [6, 7]])
        ->assertSessionHasNoErrors();

    // Oktober tanpa Sabtu (3, 10, 17, 24, 31) & Ahad, tanpa 19–20: 31 − 9 − 2 = 20; s.d. 22 Okt: 22 − 6 − 2 = 14.
    $this->actingAs($this->admin)
        ->get(route('academic.attendance.recap', ['class' => $this->class->id, 'periode' => 'bulan-2026-10', 'jenis' => 'harian']))
        ->assertInertia(fn (Assert $page) => $page->where('effective.days', 20)->where('effective.due', 14)->where('effective.unfilled', 14));
});
