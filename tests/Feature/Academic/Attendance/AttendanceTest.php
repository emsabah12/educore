<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Presensi harian (AC-4a, PRD-002 §17).
 *
 * Akun `guru` = pegawai MA Unit 1, wali kelas X-A (tahun berjalan) berisi Ahmad & Aisyah.
 * XI-A (MA Unit 1) tanpa wali kelas. Admin Yayasan berperan sebagai pengelola kelas (operator).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->today = BusinessClock::todayString();
    $this->daysAgo = fn (int $days): string => BusinessClock::today()->subDays($days)->toDateString();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA & MDA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    foreach ([['X-A', 10, $employee->id], ['XI-A', 11, null]] as [$name, $grade, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $this->ma, 'academic_year_id' => $this->current->id, 'grade' => $grade, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
            'class_id' => prsClass('X-A')->id,
        ])->assertSessionHasNoErrors();
    }

    $this->ahmad = prsEnrollment('Ahmad Fauzi');
    $this->aisyah = prsEnrollment('Aisyah Putri');
});

function prsClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function prsEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

/** Guru punya dua penugasan: pilih lembaga kerja MA Unit 1 dengan sesi yang bersih. */
function prsAsGuru(TestCase $test, mixed $guru): void
{
    $test->flushSession();
    DevAccounts::chooseWorkspace($test, $guru, 'U1-MA');
}

/**
 * @param  array<string, array{status: string, note?: string}>  $entries
 * @return array<string, mixed>
 */
function prsIsian(string $date, array $entries): array
{
    return ['date' => $date, 'attendance' => $entries];
}

// ── Wali kelas ────────────────────────────────────────────────────────────────

test('wali kelas membuka presensi dari Kelas saya: semua Hadir, 7 hari terakhir; simpan tercatat di audit tanpa isi keterangan', function () {
    prsAsGuru($this, $this->guru);

    $this->actingAs($this->guru)
        ->get(route('academic.my-attendance.show', ['class' => prsClass('X-A')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/attendance/sheet')
            ->where('date', $this->today)
            ->where('window.max', $this->today)
            ->where('window.min', max($this->current->start_date->toDateString(), ($this->daysAgo)(6)))
            ->where('asHomeroom', true)
            ->has('sheet.students', 2)
            ->where('sheet.students.0.name', 'Ahmad Fauzi')
            ->where('sheet.students.0.status', 'H')
            ->where('sheet.session', null),
        );

    $this->actingAs($this->guru)
        ->post(route('academic.my-attendance.store', ['class' => prsClass('X-A')->id]), prsIsian($this->today, [
            $this->ahmad => ['status' => 'S', 'note' => '  Demam   tinggi '],
            $this->aisyah => ['status' => 'H', 'note' => ''],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('academic.my-attendance.show', ['class' => prsClass('X-A')->id, 'tanggal' => $this->today]));

    $this->actingAs($this->guru)
        ->get(route('academic.my-attendance.show', ['class' => prsClass('X-A')->id, 'tanggal' => $this->today]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.students.0.status', 'S')
            ->where('sheet.students.0.note', 'Demam tinggi')
            ->where('sheet.students.1.note', null)
            ->where('sheet.session.recorded_by', 'Guru MA & MDA'),
        );

    $audit = DB::table('audit_logs')->where('action', AcademicAuditAction::ATTENDANCE_RECORDED)->sole();

    expect(json_decode((string) $audit->changes, true))
        ->toEqual(['kelas' => 'X-A', 'tanggal' => $this->today, 'hadir' => 1, 'sakit' => 1, 'izin' => 0, 'alpa' => 0, 'koreksi' => false])
        ->and((string) $audit->changes)->not->toContain('Demam');
});

test('koreksi mengganti isi sesi yang sama dan tercatat sebagai koreksi', function () {
    prsAsGuru($this, $this->guru);
    $url = route('academic.my-attendance.store', ['class' => prsClass('X-A')->id]);

    $this->actingAs($this->guru)->post($url, prsIsian($this->today, [$this->ahmad => ['status' => 'S'], $this->aisyah => ['status' => 'H']]))->assertSessionHasNoErrors();
    $this->actingAs($this->guru)->post($url, prsIsian($this->today, [$this->ahmad => ['status' => 'A'], $this->aisyah => ['status' => 'I']]))->assertSessionHasNoErrors();

    expect(DB::table('ac_attendance_sessions')->count())->toBe(1)
        ->and(DB::table('ac_attendance_records')->where('enrollment_id', $this->ahmad)->value('status'))->toBe('A')
        ->and(DB::table('ac_attendance_records')->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::ATTENDANCE_RECORDED)->where('changes->koreksi', true)->count())->toBe(1);
});

// ── Batas tanggal ─────────────────────────────────────────────────────────────

test('batas tanggal: wali kelas 7 hari terakhir; operator seluruh tahun ajaran; masa depan ditolak', function () {
    $entries = [$this->ahmad => ['status' => 'H'], $this->aisyah => ['status' => 'H']];

    prsAsGuru($this, $this->guru);
    $mine = route('academic.my-attendance.store', ['class' => prsClass('X-A')->id]);

    $this->actingAs($this->guru)->post($mine, prsIsian(($this->daysAgo)(6), $entries))->assertSessionHasNoErrors();
    $this->actingAs($this->guru)->post($mine, prsIsian(($this->daysAgo)(7), $entries))
        ->assertSessionHasErrors(['date' => 'Wali kelas hanya bisa mengisi atau mengoreksi presensi 7 hari terakhir. Minta Operator Akademik untuk tanggal yang lebih lama.']);
    $this->actingAs($this->guru)->post($mine, prsIsian(BusinessClock::today()->addDay()->toDateString(), $entries))
        ->assertSessionHasErrors(['date' => 'Presensi tidak bisa diisi untuk tanggal di masa depan atau sesudah tahun ajaran selesai.']);

    $this->flushSession();
    $operator = route('academic.attendance.store', ['class' => prsClass('X-A')->id]);

    $this->actingAs($this->admin)->post($operator, prsIsian(($this->daysAgo)(7), $entries))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post($operator, prsIsian($this->current->start_date->subDay()->toDateString(), $entries))
        ->assertSessionHasErrors(['date' => 'Tanggal presensi harus di dalam tahun ajaran kelas ini.']);

    expect(DB::table('ac_attendance_sessions')->count())->toBe(2);
});

// ── Daftar siswa per tanggal & isian ──────────────────────────────────────────

test('daftar siswa mengikuti tanggal: siswa yang pindah kelas hanya muncul sebelum tanggal pindah; isian tidak lengkap ditolak', function () {
    $moveDate = ($this->daysAgo)(2);
    $member = (string) DB::table('ac_class_members')->where('enrollment_id', $this->aisyah)->value('id');
    $this->actingAs($this->admin)
        ->post(route('academic.class-members.move', ['member' => $member]), ['target_class_id' => prsClass('XI-A')->id, 'date' => $moveDate])
        ->assertSessionHasNoErrors();

    $show = fn (string $date) => $this->actingAs($this->admin)->get(route('academic.attendance.show', ['class' => prsClass('X-A')->id, 'tanggal' => $date]));

    $show(($this->daysAgo)(3))->assertInertia(fn (Assert $page) => $page->has('sheet.students', 2));
    $show($moveDate)->assertInertia(fn (Assert $page) => $page->has('sheet.students', 1)->where('sheet.students.0.name', 'Ahmad Fauzi'));

    $url = route('academic.attendance.store', ['class' => prsClass('X-A')->id]);

    $this->actingAs($this->admin)->post($url, prsIsian($this->today, [$this->ahmad => ['status' => 'H'], $this->aisyah => ['status' => 'H']]))
        ->assertSessionHasErrors(['form' => 'Daftar siswa kelas ini berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.']);
    $this->actingAs($this->admin)->post($url, prsIsian(($this->daysAgo)(3), [$this->aisyah => ['status' => 'H']]))
        ->assertSessionHasErrors(['form' => 'Pilih status kehadiran untuk: Ahmad Fauzi.']);
    $this->actingAs($this->admin)->post($url, prsIsian(($this->daysAgo)(3), [$this->ahmad => ['status' => 'X'], $this->aisyah => ['status' => 'H']]))
        ->assertSessionHasErrors(['form' => 'Pilih status kehadiran untuk: Ahmad Fauzi.']);
    $this->actingAs($this->admin)->post($url, prsIsian(($this->daysAgo)(3), [$this->ahmad => ['status' => 'S', 'note' => str_repeat('a', 201)], $this->aisyah => ['status' => 'H']]))
        ->assertSessionHasErrors(["attendance.{$this->ahmad}.note"]);

    expect(DB::table('ac_attendance_sessions')->count())->toBe(0);
});

// ── Hak akses & tahun lewat ───────────────────────────────────────────────────

test('hak akses: wali kelas hanya kelasnya; guru tanpa izin kelola 403 di pintu operator; di luar cakupan 404', function () {
    prsAsGuru($this, $this->guru);

    $this->actingAs($this->guru)->get(route('academic.my-attendance.show', ['class' => prsClass('XI-A')->id]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.attendance.show', ['class' => prsClass('X-A')->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))->get(route('academic.attendance.show', ['class' => prsClass('X-A')->id]))->assertNotFound();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('academic.attendance.store', ['class' => prsClass('X-A')->id]), prsIsian($this->today, [$this->ahmad => ['status' => 'H'], $this->aisyah => ['status' => 'H']]))
        ->assertForbidden();

    expect(DB::table('ac_attendance_sessions')->count())->toBe(0);
});

test('tahun ajaran sudah lewat: presensi tidak bisa diisi', function () {
    $this->current->forceFill(['is_current' => false, 'end_date' => ($this->daysAgo)(1)])->save();

    $this->actingAs($this->admin)
        ->get(route('academic.attendance.show', ['class' => prsClass('X-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('window', null)->where('sheet', null));

    $this->actingAs($this->admin)
        ->post(route('academic.attendance.store', ['class' => prsClass('X-A')->id]), prsIsian(($this->daysAgo)(2), [$this->ahmad => ['status' => 'H'], $this->aisyah => ['status' => 'H']]))
        ->assertSessionHasErrors(['date' => 'Presensi kelas ini tidak bisa diisi (tahun ajaran sudah lewat atau belum mulai).']);
});
