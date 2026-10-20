<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassMember;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Anggota kelas (AC-1d-b, PRD-002 §4.2, §4.3, §6, §9): detail kelas, masukkan siswa, pindah,
 * keluarkan, kelas di detail siswa, kelas di form Tambah siswa.
 *
 * Data uji (SMP Unit 3, masuk 14 Juli 2025): Ahmad (L), Aisyah (P), Citra (P); Budi di MTs Unit 1.
 * Kelas tahun berjalan: VII-A, VII-B, VII-C (nonaktif); tahun depan: VIII-A; MTs: VII-MTs.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->operator = DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME);
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP')->id;
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS')->id;
    $this->today = BusinessClock::todayString();

    $this->current = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $first = (int) substr($this->current->name, 0, 4);
    $this->nextYear = new AcademicYear;
    $this->nextYear->forceFill([
        'tenant_id' => $this->tenant->id, 'calendar_id' => $this->current->calendar_id, 'name' => ($first + 1).'/'.($first + 2),
        'start_date' => ($first + 1).'-07-01', 'end_date' => ($first + 2).'-06-30', 'is_current' => false,
    ])->save();

    foreach ([['Ahmad Fauzi', 'L', $this->smp], ['Aisyah Putri', 'P', $this->smp], ['Citra Lestari', 'P', $this->smp], ['Budi Santoso', 'L', $this->mts]] as [$name, $gender, $organization]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $organization, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
    }

    foreach ([['VII-A', $this->smp, $this->current], ['VII-B', $this->smp, $this->current], ['VII-C', $this->smp, $this->current], ['VIII-A', $this->smp, $this->nextYear], ['VII-MTs', $this->mts, $this->current]] as [$name, $organization, $year]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $year->id, 'grade' => 7, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    agtClass('VII-C')->forceFill(['is_active' => false])->save();
});

function agtClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function agtEnrollment(Tenant $tenant, string $name): Enrollment
{
    $membership = Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();
    $student = Student::query()->withoutGlobalScope('tenant')->where('membership_id', $membership->id)->sole();

    return Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $student->id)->sole();
}

/**
 * Nama siswa yang masih di kelas, urut nama.
 *
 * @return list<string>
 */
function agtMembers(SchoolClass $class): array
{
    return DB::table('ac_class_members as cm')
        ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('cm.class_id', $class->id)
        ->whereNull('cm.end_date')
        ->orderBy('p.name')
        ->pluck('p.name')
        ->all();
}

function agtOpenMember(Tenant $tenant, string $name, SchoolClass $class): ClassMember
{
    return ClassMember::query()->withoutGlobalScope('tenant')
        ->where('class_id', $class->id)
        ->where('enrollment_id', agtEnrollment($tenant, $name)->id)
        ->whereNull('end_date')
        ->sole();
}

// ── Detail kelas ──────────────────────────────────────────────────────────────

test('detail kelas: calon anggota = siswa aktif lembaga tanpa kelas di tahun itu; tujuan pindah hanya kelas aktif lain', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => agtClass('VII-A')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/classes/show')
            ->where('class.name', 'VII-A')
            ->where('class.organization', 'SMP Unit 3')
            ->where('year.current', true)
            ->where('canManage', true)
            ->has('members', 0)
            ->where('candidates.0.name', 'Ahmad Fauzi')
            ->has('candidates', 3)
            ->where('moveTargets', [['value' => agtClass('VII-B')->id, 'label' => 'VII-B']]),
        );

    // Kepala Unit 1 tidak melihat SMP Unit 3; melihat kelas MTs tanpa hak kelola.
    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('academic.classes.show', ['class' => agtClass('VII-A')->id]))->assertNotFound();
    $this->actingAs($kepala)
        ->get(route('academic.classes.show', ['class' => agtClass('VII-MTs')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('candidates', []));
});

// ── Masukkan ──────────────────────────────────────────────────────────────────

test('masukkan banyak siswa sekaligus; jumlah L/P di daftar kelas; calon berkurang', function () {
    $class = agtClass('VII-A');

    $this->actingAs($this->operator)
        ->post(route('academic.class-members.store', ['class' => $class->id]), [
            'enrollment_ids' => [agtEnrollment($this->tenant, 'Ahmad Fauzi')->id, agtEnrollment($this->tenant, 'Aisyah Putri')->id],
            'start_date' => $this->today,
        ])->assertSessionHasNoErrors();

    $changes = json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_MEMBERS_ADDED)->sole()->changes, true);

    expect(agtMembers($class))->toBe(['Ahmad Fauzi', 'Aisyah Putri'])
        ->and(agtOpenMember($this->tenant, 'Ahmad Fauzi', $class)->start_date->toDateString())->toBe($this->today)
        ->and($changes['jumlah'])->toBe(2);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('classes.0.members', ['total' => 2, 'male' => 1, 'female' => 1]));

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => $class->id]))
        ->assertInertia(fn (Assert $page) => $page->has('members', 2)->has('candidates', 1));
});

test('masukkan: semua atau tidak sama sekali; masalah tiap siswa disebut sekaligus', function () {
    $url = route('academic.class-members.store', ['class' => agtClass('VII-B')->id]);

    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-A')->id]), [
        'enrollment_ids' => [agtEnrollment($this->tenant, 'Ahmad Fauzi')->id], 'start_date' => $this->today,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)->post($url, [
        'enrollment_ids' => [
            agtEnrollment($this->tenant, 'Citra Lestari')->id,
            agtEnrollment($this->tenant, 'Ahmad Fauzi')->id,
            agtEnrollment($this->tenant, 'Budi Santoso')->id,
        ],
        'start_date' => $this->today,
    ])->assertSessionHasErrors(['enrollment_ids' => '2 siswa tidak bisa dimasukkan: Ahmad Fauzi (sudah punya kelas di tahun ajaran ini), Budi Santoso (bukan siswa aktif lembaga ini).']);

    expect(agtMembers(agtClass('VII-B')))->toBe([]);

    $citra = [agtEnrollment($this->tenant, 'Citra Lestari')->id];
    $this->actingAs($this->operator)->post($url, ['enrollment_ids' => $citra, 'start_date' => '2099-01-01'])->assertSessionHasErrors(['start_date']);
    $this->actingAs($this->operator)->post($url, ['enrollment_ids' => $citra, 'start_date' => $this->current->start_date->subDay()->toDateString()])
        ->assertSessionHasErrors(['start_date']);
    $this->actingAs($this->operator)->post($url, ['enrollment_ids' => []])->assertSessionHasErrors(['enrollment_ids']);
    $this->actingAs($this->operator)->post($url, ['enrollment_ids' => ['bukan-uuid'], 'start_date' => $this->today])->assertSessionHasErrors(['enrollment_ids.0']);

    // Kelas nonaktif tidak bisa diisi.
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-C')->id]), ['enrollment_ids' => $citra, 'start_date' => $this->today])
        ->assertSessionHasErrors(['form' => 'Kelas VII-C nonaktif, jadi tidak bisa diisi siswa.']);

    expect(DB::table('ac_class_members')->count())->toBe(1);
});

test('kelas tahun depan: tanggal masuk otomatis awal tahun ajaran, walau siswa sudah punya kelas tahun ini', function () {
    $ahmad = agtEnrollment($this->tenant, 'Ahmad Fauzi')->id;

    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-A')->id]), [
        'enrollment_ids' => [$ahmad], 'start_date' => $this->today,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VIII-A')->id]), [
        'enrollment_ids' => [$ahmad],
    ])->assertSessionHasNoErrors();

    expect(agtOpenMember($this->tenant, 'Ahmad Fauzi', agtClass('VIII-A'))->start_date->toDateString())
        ->toBe($this->nextYear->start_date->toDateString());
});

// ── Pindah & keluarkan ────────────────────────────────────────────────────────

test('pindah kelas: lama ditutup, baru dibuka di tanggal yang sama; hanya ke kelas aktif tahun yang sama', function () {
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-A')->id]), [
        'enrollment_ids' => [agtEnrollment($this->tenant, 'Ahmad Fauzi')->id], 'start_date' => $this->current->start_date->toDateString(),
    ])->assertSessionHasNoErrors();

    $member = agtOpenMember($this->tenant, 'Ahmad Fauzi', agtClass('VII-A'));
    $move = route('academic.class-members.move', ['member' => $member->id]);

    $this->actingAs($this->operator)->post($move, ['target_class_id' => agtClass('VIII-A')->id, 'date' => $this->today])
        ->assertSessionHasErrors(['target_class_id' => 'Kelas tujuan harus di lembaga dan tahun ajaran yang sama.']);
    $this->actingAs($this->operator)->post($move, ['target_class_id' => agtClass('VII-C')->id, 'date' => $this->today])
        ->assertSessionHasErrors(['target_class_id']);
    $this->actingAs($this->operator)->post($move, ['target_class_id' => agtClass('VII-B')->id, 'date' => $this->current->start_date->subDay()->toDateString()])
        ->assertSessionHasErrors(['date']);

    $this->actingAs($this->operator)->post($move, ['target_class_id' => agtClass('VII-B')->id, 'date' => $this->today])
        ->assertSessionHasNoErrors();

    expect($member->fresh()?->end_date?->toDateString())->toBe($this->today)
        ->and(agtMembers(agtClass('VII-A')))->toBe([])
        ->and(agtMembers(agtClass('VII-B')))->toBe(['Ahmad Fauzi'])
        ->and(agtOpenMember($this->tenant, 'Ahmad Fauzi', agtClass('VII-B'))->start_date->toDateString())->toBe($this->today)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_MEMBER_MOVED)->count())->toBe(1);

    // Riwayat tetap tampil di detail kelas lama.
    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => agtClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('members', 0)->where('former.0.name', 'Ahmad Fauzi'));
});

test('keluarkan dari kelas: pendaftaran di lembaga tetap aktif dan siswa kembali menjadi calon anggota', function () {
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-A')->id]), [
        'enrollment_ids' => [agtEnrollment($this->tenant, 'Aisyah Putri')->id], 'start_date' => $this->today,
    ])->assertSessionHasNoErrors();

    $member = agtOpenMember($this->tenant, 'Aisyah Putri', agtClass('VII-A'));

    $this->actingAs($this->operator)->post(route('academic.class-members.remove', ['member' => $member->id]), ['date' => $this->today])
        ->assertSessionHasNoErrors();
    // Sudah keluar: aksi kedua ditolak.
    $this->actingAs($this->operator)->post(route('academic.class-members.remove', ['member' => $member->id]), ['date' => $this->today])
        ->assertSessionHasErrors(['form']);

    expect(agtMembers(agtClass('VII-A')))->toBe([])
        ->and(agtEnrollment($this->tenant, 'Aisyah Putri')->isOpen())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_MEMBER_REMOVED)->count())->toBe(1);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => agtClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('candidates', 3));
});

test('kelas tahun ajaran yang sudah lewat: anggotanya tidak bisa dipindah atau dikeluarkan', function () {
    $first = (int) substr($this->current->name, 0, 4);
    $past = new AcademicYear;
    $past->forceFill([
        'tenant_id' => $this->tenant->id, 'calendar_id' => $this->current->calendar_id, 'name' => ($first - 1).'/'.$first,
        'start_date' => ($first - 1).'-07-01', 'end_date' => $first.'-06-30', 'is_current' => false,
    ])->save();
    $class = new SchoolClass;
    $class->forceFill(['tenant_id' => $this->tenant->id, 'organization_id' => $this->smp, 'academic_year_id' => $past->id, 'grade' => 7, 'name' => 'VII-Lama'])->save();
    $member = new ClassMember;
    $member->forceFill([
        'tenant_id' => $this->tenant->id, 'organization_id' => $this->smp, 'academic_year_id' => $past->id, 'class_id' => $class->id,
        'enrollment_id' => agtEnrollment($this->tenant, 'Ahmad Fauzi')->id, 'start_date' => '2025-07-14',
    ])->save();

    $this->actingAs($this->operator)->post(route('academic.class-members.remove', ['member' => $member->id]), ['date' => $this->today])
        ->assertSessionHasErrors(['form' => 'Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat, tidak bisa diubah.']);

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => $class->id]))
        ->assertInertia(fn (Assert $page) => $page->where('year.past', true)->where('canManage', false)->has('members', 1));
});

// ── Detail siswa & tambah siswa ───────────────────────────────────────────────

test('detail siswa menampilkan kelas per tahun ajaran dengan aksi pindah bagi pengelola kelas', function () {
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-A')->id]), [
        'enrollment_ids' => [agtEnrollment($this->tenant, 'Ahmad Fauzi')->id], 'start_date' => $this->today,
    ])->assertSessionHasNoErrors();

    $studentId = agtEnrollment($this->tenant, 'Ahmad Fauzi')->student_id;

    $this->actingAs($this->operator)
        ->get(route('academic.students.show', ['student' => $studentId]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('student.classes.0.class_name', 'VII-A')
            ->where('student.classes.0.open', true)
            ->where('student.classes.0.can_manage', true)
            ->where('student.classes.0.move_targets', [['value' => agtClass('VII-B')->id, 'label' => 'VII-B']]),
        );

    // Admin Yayasan juga boleh mengelola kelas di semua lembaga.
    $this->actingAs($this->admin)->get(route('academic.students.show', ['student' => $studentId]))
        ->assertInertia(fn (Assert $page) => $page->where('student.classes.0.can_manage', true));
});

test('tambah siswa langsung ke kelas: tanggal masuk kelas = awal tahun ajaran bila masuk lembaga lebih awal', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.students.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('options.classes.'.$this->smp, 2)
            ->where('options.classes.'.$this->smp.'.0.label', 'VII-A ('.$this->current->name.')'),
        );

    $isian = ['gender' => 'P', 'organization_id' => $this->smp, 'start_date' => '2025-07-14', 'entry_type' => 'BARU'];

    $this->actingAs($this->operator)->post(route('academic.students.store'), $isian + ['name' => 'Dewi Sartika', 'class_id' => agtClass('VII-A')->id])
        ->assertSessionHasNoErrors();

    expect(agtMembers(agtClass('VII-A')))->toBe(['Dewi Sartika'])
        ->and(agtOpenMember($this->tenant, 'Dewi Sartika', agtClass('VII-A'))->start_date->toDateString())
        ->toBe($this->current->start_date->toDateString());

    // Kelas nonaktif: tidak ada yang tersimpan, termasuk orangnya.
    $this->actingAs($this->operator)->post(route('academic.students.store'), $isian + ['name' => 'Eka Putri', 'class_id' => agtClass('VII-C')->id])
        ->assertSessionHasErrors(['class_id']);
    // Kelas di lembaga lain: di luar cakupan operator.
    $this->actingAs($this->operator)->post(route('academic.students.store'), $isian + ['name' => 'Eka Putri', 'class_id' => agtClass('VII-MTs')->id])
        ->assertNotFound();
    // Admin: kelas MTs untuk pendaftaran SMP ditolak.
    $this->actingAs($this->admin)->post(route('academic.students.store'), $isian + ['name' => 'Eka Putri', 'class_id' => agtClass('VII-MTs')->id])
        ->assertSessionHasErrors(['class_id' => 'Kelas harus di lembaga yang sama dengan pendaftaran.']);

    expect(Person::query()->where('name', 'Eka Putri')->exists())->toBeFalse();
});

// ── Hak akses ─────────────────────────────────────────────────────────────────

test('anggota kelas: di luar cakupan 404, tanpa izin kelola 403', function () {
    $this->actingAs($this->admin)->post(route('academic.class-members.store', ['class' => agtClass('VII-MTs')->id]), [
        'enrollment_ids' => [agtEnrollment($this->tenant, 'Budi Santoso')->id], 'start_date' => $this->today,
    ])->assertSessionHasNoErrors();
    $budi = agtOpenMember($this->tenant, 'Budi Santoso', agtClass('VII-MTs'));
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => agtClass('VII-MTs')->id]), ['enrollment_ids' => [agtEnrollment($this->tenant, 'Budi Santoso')->id]])
        ->assertNotFound();
    $this->actingAs($this->operator)->post(route('academic.class-members.remove', ['member' => $budi->id]), ['date' => $this->today])->assertNotFound();
    $this->actingAs($kepala)->post(route('academic.class-members.remove', ['member' => $budi->id]), ['date' => $this->today])->assertForbidden();
    $this->actingAs($this->operator)->post(route('academic.class-members.move', ['member' => (string) Str::uuid7()]), ['target_class_id' => agtClass('VII-B')->id])
        ->assertNotFound();

    expect($budi->fresh()?->isOpen())->toBeTrue();
});
