<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Buka kembali pendaftaran (PRD-002 §18 P2): Admin Yayasan saja, alasan wajib.
 *
 * Ahmad (SMP Unit 3, nomor induk SMP-001) di kelas VII-A tahun berjalan; pendaftarannya ditutup
 * "Lulus" hari ini (kelas VII-A ikut tertutup).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->today = BusinessClock::todayString();
    $current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->smp, 'academic_year_id' => $current->id, 'grade' => 7, 'name' => 'VII-A',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('academic.students.store'), [
        'name' => 'Ahmad Fauzi', 'gender' => 'L', 'organization_id' => $this->smp, 'student_number' => 'SMP-001',
        'start_date' => '2025-07-14', 'entry_type' => 'BARU', 'class_id' => SchoolClass::query()->withoutGlobalScope('tenant')->sole()->id,
    ])->assertSessionHasNoErrors();

    $this->enrollment = Enrollment::query()->withoutGlobalScope('tenant')->sole();
    $this->studentId = $this->enrollment->student_id;

    $this->actingAs($this->admin)
        ->post(route('academic.enrollments.close', ['student' => $this->studentId, 'enrollment' => $this->enrollment->id]), [
            'end_date' => $this->today, 'end_reason' => 'LULUS',
        ])
        ->assertSessionHasNoErrors();
});

function bkbReopen(TestCase $test, mixed $user, string $studentId, string $enrollmentId, ?string $reason = 'Salah pilih siswa saat proses kelulusan'): TestResponse
{
    return $test->actingAs($user)->post(route('academic.enrollments.reopen', ['student' => $studentId, 'enrollment' => $enrollmentId]), ['reason' => $reason]);
}

test('Admin Yayasan membuka kembali: pendaftaran berjalan lagi, kelas yang ikut tertutup dibuka lagi, tercatat tanpa isi alasan', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.students.show', ['student' => $this->studentId]))
        ->assertInertia(fn (Assert $page) => $page->where('student.enrollments.history.0.can_reopen', true));

    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id)->assertSessionHasNoErrors();

    $this->enrollment->refresh();
    $audit = DB::table('audit_logs')->where('action', AcademicAuditAction::ENROLLMENT_REOPENED)->sole();

    expect($this->enrollment->end_date)->toBeNull()
        ->and($this->enrollment->end_reason)->toBeNull()
        ->and(DB::table('ac_class_members')->where('enrollment_id', $this->enrollment->id)->whereNull('end_date')->count())->toBe(1)
        ->and(json_decode((string) $audit->changes, true))->toEqual([
            'nama' => 'Ahmad Fauzi', 'lembaga' => 'U3-SMP', 'tanggal_keluar_lama' => $this->today, 'alasan_keluar_lama' => 'LULUS',
            'alasan_diisi' => true, 'kelas_dibuka' => 1,
        ])
        ->and((string) $audit->changes)->not->toContain('Salah pilih');

    $this->actingAs($this->admin)
        ->get(route('academic.students.show', ['student' => $this->studentId]))
        ->assertInertia(fn (Assert $page) => $page->where('student.enrollments.current.0.can_reopen', false));
});

test('hanya Admin Yayasan; alasan wajib; pendaftaran yang masih berjalan ditolak', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $this->studentId]))
        ->assertInertia(fn (Assert $page) => $page->where('student.enrollments.history.0.can_reopen', false));
    bkbReopen($this, $this->operator, $this->studentId, $this->enrollment->id)->assertForbidden();

    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id, '  ')
        ->assertSessionHasErrors(['reason' => 'Tulis alasan membuka kembali pendaftaran ini.']);

    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id)->assertSessionHasNoErrors();
    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id)
        ->assertSessionHasErrors(['form' => 'Pendaftaran ini masih berjalan.']);
});

test('ditolak bila siswa sudah punya pendaftaran berjalan lain di lembaga itu', function () {
    // Nomor induk SMP-001 tidak bisa dipakai siswa lain (aturan AC-1c-b1), jadi bentrok nomor induk
    // hanya pengaman tambahan di service; yang diuji di sini pendaftaran berjalan yang baru.
    $this->actingAs($this->admin)->post(route('academic.enrollments.store', ['student' => $this->studentId]), [
        'organization_id' => $this->smp, 'start_date' => $this->today, 'entry_type' => 'BARU',
    ])->assertSessionHasNoErrors();

    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id)
        ->assertSessionHasErrors(['form' => 'Siswa ini sudah punya pendaftaran berjalan di SMP Unit 3. Pendaftaran lama tidak bisa dibuka lagi.']);

    expect($this->enrollment->refresh()->end_date)->not->toBeNull();
});

test('kelas yang sudah nonaktif tidak dibuka lagi; pendaftaran tetap dibuka', function () {
    SchoolClass::query()->withoutGlobalScope('tenant')->update(['is_active' => false]);

    bkbReopen($this, $this->admin, $this->studentId, $this->enrollment->id)->assertSessionHasNoErrors();

    expect($this->enrollment->refresh()->end_date)->toBeNull()
        ->and(DB::table('ac_class_members')->where('enrollment_id', $this->enrollment->id)->whereNull('end_date')->count())->toBe(0)
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::ENROLLMENT_REOPENED)->sole()->changes, true)['kelas_dibuka'])->toBe(0);
});
