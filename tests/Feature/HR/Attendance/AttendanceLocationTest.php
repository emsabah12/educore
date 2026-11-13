<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Attendance\WorkLocations;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Presensi lokasi HP (PRD-001 §14.6, HR-4d; OD-HR-22, OD-HR-23).
 *
 * "Hari ini" Kamis 22 Okt 2026. Akun `guru` = pegawai berpenempatan utama MA Unit 1. Titik MA:
 * -6.900000, 107.600000. Posisi -6.900500 ≈ 56 m (di dalam radius 150 m); -6.905000 ≈ 556 m (di luar).
 */

const LKS_INSIDE = ['latitude' => '-6.900500', 'longitude' => '107.600000', 'accuracy' => '12'];

const LKS_OUTSIDE = ['latitude' => '-6.905000', 'longitude' => '107.600000', 'accuracy' => '12'];

function lksAt(mixed $test, string $moment): void
{
    $test->travelTo(CarbonImmutable::parse($moment, BusinessClock::timezone()));
}

function lksGuru(mixed $test): void
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $test->guru, 'U1-MA');
}

function lksMode(mixed $test, string $mode): void
{
    $test->flushSession();
    $test->actingAs($test->admin)
        ->post(route('hr.attendance-location.assign'), ['target' => 'lembaga', 'organization_id' => $test->ma, 'mode' => $mode])
        ->assertSessionHasNoErrors();
}

function lksPoint(mixed $test, string $radius = '150'): void
{
    $test->flushSession();
    $test->actingAs($test->admin)
        ->put(route('hr.work-locations.set', ['organization' => $test->ma]), ['latitude' => '-6.900000', 'longitude' => '107.600000', 'radius' => $radius])
        ->assertSessionHasNoErrors();
}

function lksRecord(mixed $test): ?stdClass
{
    $row = DB::table('hr_attendances')->where('employee_id', $test->employee)->first();

    return $row instanceof stdClass ? $row : null;
}

beforeEach(function () {
    lksAt($this, '2026-10-22 06:50:00');

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->guru = DevAccounts::user('guru');
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->kepala = DevAccounts::user('kepala.unit1');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $this->employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id,
        name: 'Guru', gender: Gender::Female, birthPlace: null, birthDate: null, phone: null, address: null,
        employeeNumber: null, nuptk: null, educationLevel: null, educationMajor: null,
        employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'), classificationId: null, startDate: '2020-07-01',
        organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
});

test('jarak haversine masuk akal', function () {
    expect(WorkLocations::distance(-6.9, 107.6, -6.9005, 107.6))->toBeGreaterThan(54.0)->toBeLessThan(57.0)
        ->and(WorkLocations::distance(-6.9, 107.6, -6.905, 107.6))->toBeGreaterThan(550.0)->toBeLessThan(560.0);
});

test('bawaan "Tidak memakai lokasi": posisi diabaikan, tidak ada data lokasi', function () {
    lksPoint($this);
    lksGuru($this);

    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page->where('locationMode', 'TIDAK'));

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_OUTSIDE)->assertSessionHasNoErrors();

    $row = lksRecord($this);
    expect([$row?->check_in_location, $row?->check_in_distance])->toBe([null, null]);
});

test('Catat lokasi: hasil & jarak disimpan tanpa koordinat; di luar radius tetap tersimpan; tanpa posisi = tidak tersedia', function () {
    lksPoint($this);
    lksMode($this, 'CATAT');
    lksGuru($this);

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_OUTSIDE)->assertSessionHasNoErrors();

    lksAt($this, '2026-10-22 14:05:00');
    lksGuru($this);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-out'))->assertSessionHasNoErrors();

    $row = lksRecord($this);
    expect([$row?->check_in_location, (int) $row?->check_in_distance, (int) $row?->check_in_accuracy, $row?->check_in_location_org_id])
        ->toBe([WorkLocations::OUTSIDE, 556, 12, $this->ma])
        ->and([$row?->check_out_location, $row?->check_out_distance])->toBe([WorkLocations::UNAVAILABLE, null]);

    // Koordinat HP tidak tersimpan di kolom mana pun, tidak juga di audit (OD-HR-22).
    $stored = json_encode([(array) $row, DB::table('audit_logs')->get()->all()]);
    expect($stored)->not->toContain('-6.905')->not->toContain('-6.9050');

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_CHECKED_IN)->sole();
    expect(json_decode((string) $audit->changes, true))->toEqual(['tanggal' => '2026-10-22', 'jam' => '06:50', 'terlambat_menit' => 0, 'lokasi' => 'DI_LUAR', 'jarak_m' => 556]);

    $this->actingAs($this->guru)->get(route('hr.my-attendance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locationMode', 'CATAT')
            ->where('today.check_in_location', 'Di luar lokasi · 556 m')
            ->where('today.check_out_location', 'Lokasi tidak tersedia'),
        );

    // Rekap: hari dengan absen di luar lokasi dihitung sekali.
    expect(app(AttendanceRecap::class)->forEmployee($this->tenant->id, $this->employee, '2026-10')['counts']['outside'] ?? null)->toBe(1);
});

test('Wajib di dalam radius: di luar, akurasi buruk, tanpa posisi, dan titik belum diatur ditolak; di dalam diterima', function () {
    lksMode($this, 'WAJIB');
    lksGuru($this);

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_INSIDE)
        ->assertSessionHasErrors(['location' => 'Titik lokasi lembaga Anda belum diatur. Hubungi Admin SDM.']);

    lksPoint($this);
    lksGuru($this);

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'))
        ->assertSessionHasErrors(['location' => 'Lokasi HP tidak terbaca. Izinkan akses lokasi di browser lalu coba lagi, atau minta petugas presensi mencatatkan.']);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), ['accuracy' => '250'] + LKS_INSIDE)
        ->assertSessionHasErrors(['location' => 'Sinyal lokasi kurang akurat (±250 m). Coba di tempat terbuka atau nyalakan GPS, lalu ulangi.']);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_OUTSIDE)
        ->assertSessionHasErrors(['location' => 'Anda berada sekitar 556 m dari MA Unit 1, di luar radius 150 m. Mendekatlah, atau minta petugas presensi mencatatkan (mis. dinas luar).']);

    expect(lksRecord($this))->toBeNull();

    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_INSIDE)->assertSessionHasNoErrors();
    expect([lksRecord($this)?->check_in_location, (int) lksRecord($this)?->check_in_distance])->toBe([WorkLocations::INSIDE, 56]);
});

test('petugas mengubah jam masuk absen mandiri: data lokasinya dikosongkan; badge lokasi di lembar presensi', function () {
    lksPoint($this);
    lksMode($this, 'CATAT');
    lksGuru($this);
    $this->actingAs($this->guru)->post(route('hr.my-attendance.check-in'), LKS_OUTSIDE)->assertSessionHasNoErrors();

    lksAt($this, '2026-10-22 08:00:00');
    $this->flushSession();
    $this->actingAs($this->kepala)->get(route('hr.attendance.index', ['lembaga' => $this->ma, 'tanggal' => '2026-10-22']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.record.check_in_location', ['result' => 'DI_LUAR', 'distance' => 556, 'accuracy' => 12, 'place' => 'MA Unit 1']),
        );

    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), [
        'organization_id' => $this->ma, 'date' => '2026-10-22',
        'rows' => [$this->employee => ['status' => 'DINAS_LUAR', 'check_in' => '06:45', 'check_out' => '', 'note' => 'Rapat dinas']],
    ])->assertSessionHasNoErrors();

    $row = lksRecord($this);
    expect([$row?->check_in_source, $row?->check_in_location, $row?->check_in_distance, $row?->check_in_location_org_id])->toBe(['PETUGAS', null, null, null]);
});

test('pengaturan titik & aturan: validasi, hapus, audit tanpa koordinat; hanya Admin SDM / Admin Yayasan', function () {
    $this->actingAs($this->admin)->get(route('hr.leave-settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendanceLocation.radius', ['min' => 50, 'max' => 1000, 'default' => 150])
            ->has('attendanceLocation.modes', 3)
            ->where('attendanceLocation.rules', []),
        );

    $set = fn (array $data) => $this->actingAs($this->admin)->put(route('hr.work-locations.set', ['organization' => $this->ma]), $data);

    $set(['latitude' => '-95', 'longitude' => '107.6', 'radius' => '150'])->assertSessionHasErrors(['latitude']);
    $set(['latitude' => '-6.9', 'longitude' => 'abc', 'radius' => '150'])->assertSessionHasErrors(['longitude']);
    $set(['latitude' => '-6.9', 'longitude' => '107.6', 'radius' => '20'])->assertSessionHasErrors(['radius' => 'Radius 50–1000 meter.']);
    $set(['latitude' => '-6,9', 'longitude' => '107,6', 'radius' => '200'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('hr.attendance-location.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'mode' => 'ASAL'])
        ->assertSessionHasErrors(['mode']);
    $this->actingAs($this->admin)->post(route('hr.attendance-location.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'mode' => 'WAJIB'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('hr.leave-settings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendanceLocation.rules.0.target', 'Semua MA')
            ->where('attendanceLocation.rules.0.days', 'Wajib di dalam radius'),
        );

    $location = DB::table('hr_work_locations')->where('organization_id', $this->ma)->sole();
    expect([(float) $location->latitude, (float) $location->longitude, (int) $location->radius])->toBe([-6.9, 107.6, 200]);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::WORK_LOCATION_SET)->sole();
    expect((string) $audit->changes)->not->toContain('107.6');

    $this->actingAs($this->admin)->delete(route('hr.work-locations.remove', ['organization' => $this->ma]))->assertSessionHasNoErrors();
    expect(DB::table('hr_work_locations')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::WORK_LOCATION_REMOVED)->count())->toBe(1);

    $this->flushSession();
    $this->actingAs($this->kepala)->put(route('hr.work-locations.set', ['organization' => $this->ma]), ['latitude' => '-6.9', 'longitude' => '107.6', 'radius' => '150'])->assertForbidden();
    $this->actingAs($this->kepala)->post(route('hr.attendance-location.assign'), ['target' => 'lembaga', 'organization_id' => $this->ma, 'mode' => 'TIDAK'])->assertForbidden();
    lksGuru($this);
    $this->actingAs($this->guru)->delete(route('hr.work-locations.remove', ['organization' => $this->ma]))->assertForbidden();
});
