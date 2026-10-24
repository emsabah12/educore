<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Leave\LeaveType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Lembar presensi pegawai oleh petugas (PRD-001 §14.3, HR-4b).
 *
 * "Sekarang" Kamis 22 Okt 2026 15.00. Penempatan utama MA Unit 1: Guru (akun `guru`, nama orang
 * "Guru MA & MDA") sudah absen mandiri 07.20; Budi cuti hari ini; Citra belum diisi. Dedi di SMK Unit 3.
 * Jam kerja bawaan 07.00–14.00, toleransi 15 menit.
 */

function lppEmployee(mixed $test, string $name, string $organizationCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Male,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $organizationCode)->id,
        positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

/**
 * @param  array<string, array<string, string>>  $rows
 * @return array<string, mixed>
 */
function lppIsian(mixed $test, string $date, array $rows): array
{
    return ['organization_id' => $test->ma, 'date' => $date, 'rows' => $rows];
}

function lppRecord(string $employeeId): ?stdClass
{
    $row = DB::table('hr_attendances')->where('employee_id', $employeeId)->first();

    return $row instanceof stdClass ? $row : null;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->kepala = DevAccounts::user('kepala.unit1');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->guruMembership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();

    $this->guruId = lppEmployee($this, 'Guru', 'U1-MA', $this->guruMembership->id);
    $this->budi = lppEmployee($this, 'Budi', 'U1-MA');
    $this->citra = lppEmployee($this, 'Citra', 'U1-MA');
    $this->dedi = lppEmployee($this, 'Dedi', 'U3-SMK');

    DB::table('hr_attendances')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'employee_id' => $this->guruId, 'organization_id' => $this->ma,
        'date' => '2026-10-22', 'status' => 'HADIR', 'check_in' => '07:20:00', 'check_in_source' => 'MANDIRI',
        'expected_start' => '07:00:00', 'expected_end' => '14:00:00', 'late_minutes' => 20, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $annual = LeaveType::query()->withoutGlobalScope('tenant')->where('code', 'CUTI-TAHUNAN')->sole();
    $this->actingAs($this->admin)->post(route('hr.leaves.store'), [
        'employee_id' => $this->budi, 'leave_type_id' => $annual->id, 'start_date' => '2026-10-22', 'end_date' => '2026-10-22', 'reason' => 'Keperluan keluarga',
    ])->assertSessionHasNoErrors();
    $this->flushSession();
});

test('Kepala Lembaga melihat lembar pegawai berpenempatan utama di lembaga itu: absen mandiri, cuti, belum diisi', function () {
    $this->actingAs($this->kepala)
        ->get(route('hr.attendance.index', ['lembaga' => $this->ma]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/attendance/sheet')
            ->where('date', '2026-10-22')
            ->where('earliest', '2026-10-16')
            ->has('rows', 3)
            ->where('rows.0.name', 'Budi')
            ->where('rows.0.leave', 'Cuti tahunan')
            ->where('rows.1.name', 'Citra')
            ->where('rows.1.record', null)
            ->where('rows.1.expected', ['required' => true, 'label' => '07.00–14.00 (jam kerja)'])
            ->where('rows.2.name', 'Guru MA & MDA')
            ->where('rows.2.record.check_in', '07:20')
            ->where('rows.2.record.check_in_source', 'MANDIRI')
            ->where('rows.2.record.late', 20));
});

test('simpan lembar: isi baru, lengkapi absen mandiri (sumber jam masuk tetap), cuti dilewati; audit tanpa keterangan', function () {
    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-22', [
        $this->budi => ['status' => 'HADIR', 'check_in' => '07:00', 'check_out' => '', 'note' => ''],
        $this->citra => ['status' => 'HADIR', 'check_in' => '07:30', 'check_out' => '13:45', 'note' => 'Ban bocor'],
        $this->guruId => ['status' => 'HADIR', 'check_in' => '07:20', 'check_out' => '14:00', 'note' => ''],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('hr.attendance.index', ['lembaga' => $this->ma, 'tanggal' => '2026-10-22']));

    $citra = lppRecord($this->citra);
    $guru = lppRecord($this->guruId);
    $kepalaMembership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->kepala->person_id)->sole();

    expect(lppRecord($this->budi))->toBeNull()
        ->and([$citra?->status, $citra?->check_in_source, (int) $citra?->late_minutes, (int) $citra?->early_minutes, $citra?->note])
        ->toBe(['HADIR', 'PETUGAS', 30, 15, 'Ban bocor'])
        ->and($citra?->updated_by_membership_id)->toBe($kepalaMembership->id)
        ->and([$guru?->check_in_source, $guru?->check_out_source, (int) $guru?->late_minutes])->toBe(['MANDIRI', 'PETUGAS', 20]);

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_RECORDED)->pluck('changes')->implode(' ');

    expect(DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_RECORDED)->count())->toBe(2)
        ->and($audit)->not->toContain('Ban bocor');

    // Simpan ulang tanpa perubahan: tidak ada audit baru. Mengosongkan status menghapus catatan.
    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-22', [
        $this->citra => ['status' => 'HADIR', 'check_in' => '07:30', 'check_out' => '13:45', 'note' => 'Ban bocor'],
    ]))->assertSessionHasNoErrors();
    expect(DB::table('audit_logs')->where('action', HrAuditAction::ATTENDANCE_RECORDED)->count())->toBe(2);

    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-22', [
        $this->citra => ['status' => '', 'check_in' => '', 'check_out' => '', 'note' => ''],
    ]))->assertSessionHasNoErrors();
    expect(lppRecord($this->citra))->toBeNull();
});

test('isian salah ditolak di barisnya: jam untuk Sakit, jam pulang sebelum masuk, jam pulang tanpa masuk', function () {
    $post = fn (array $row) => $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-22', [$this->citra => $row + ['note' => '']]));

    $post(['status' => 'SAKIT', 'check_in' => '07:00', 'check_out' => ''])
        ->assertSessionHasErrors(["rows.{$this->citra}.check_in" => 'Jam hanya diisi untuk Hadir atau Dinas luar.']);
    $post(['status' => 'HADIR', 'check_in' => '10:00', 'check_out' => '09:00'])
        ->assertSessionHasErrors(["rows.{$this->citra}.check_out" => 'Jam pulang tidak boleh sebelum jam masuk.']);
    $post(['status' => 'HADIR', 'check_in' => '', 'check_out' => '09:00'])
        ->assertSessionHasErrors(["rows.{$this->citra}.check_in" => 'Isi jam masuk sebelum jam pulang.']);

    expect(lppRecord($this->citra))->toBeNull();
});

test('batas tanggal: petugas lembaga 7 hari terakhir, Admin Yayasan bebas; tidak di masa depan', function () {
    $row = [$this->citra => ['status' => 'IZIN', 'check_in' => '', 'check_out' => '', 'note' => '']];

    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-15', $row))
        ->assertSessionHasErrors(['date' => 'Petugas lembaga hanya bisa mengisi atau mengoreksi 7 hari terakhir. Hubungi Admin SDM untuk tanggal yang lebih lama.']);
    $this->actingAs($this->kepala)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-23', $row))
        ->assertSessionHasErrors(['date' => 'Presensi tidak bisa diisi untuk tanggal yang akan datang.']);

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('hr.attendance.store'), lppIsian($this, '2026-10-15', $row))->assertSessionHasNoErrors();

    expect(lppRecord($this->citra)?->status)->toBe('IZIN');
});

test('akses: tanpa izin 403; lembaga di luar cakupan 404; Petugas Presensi hanya lembaga penugasannya', function () {
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.attendance.index'))->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->kepala)->get(route('hr.attendance.index', ['lembaga' => CoreFixtures::node($this->tenant, 'U3-SMK')->id]))->assertNotFound();

    // Guru (sudah punya penugasan di MA Unit 1 dari akun uji) dijadikan Petugas Presensi di sana.
    $assignment = OrganizationalAssignment::query()->withoutGlobalScope('tenant')
        ->where('membership_id', $this->guruMembership->id)->where('organization_id', $this->ma)->sole();
    CoreFixtures::assignmentRole($assignment, HrAccess::ROLE_PETUGAS_PRESENSI);
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('hr.attendance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('organizations', 1)->where('organization.value', $this->ma)->has('rows', 3));
});
