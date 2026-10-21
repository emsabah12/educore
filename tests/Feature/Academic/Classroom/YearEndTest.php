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
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Proses akhir tahun per kelas (AC-2, PRD-002 §15): naik, tinggal kelas, lulus.
 *
 * Data uji (SMP Unit 3, masuk 14 Juli 2025): Ahmad, Aisyah, Citra di VII-A (tingkat 7) dan Dewi di
 * IX-A (tingkat 9), tahun berjalan. Tahun ajaran berikutnya: VII-R (7), VIII-A (8), VIII-B (8).
 * MTs Unit 1: Budi di VII-MTs.
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

    foreach ([['Ahmad Fauzi', 'L', $this->smp], ['Aisyah Putri', 'P', $this->smp], ['Citra Lestari', 'P', $this->smp], ['Dewi Sartika', 'P', $this->smp], ['Budi Santoso', 'L', $this->mts]] as [$name, $gender, $organization]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $organization, 'start_date' => '2025-07-14', 'entry_type' => 'BARU',
        ])->assertSessionHasNoErrors();
    }

    foreach ([
        ['VII-A', 7, $this->smp, $this->current], ['IX-A', 9, $this->smp, $this->current], ['VII-MTs', 7, $this->mts, $this->current],
        ['VII-R', 7, $this->smp, $this->nextYear], ['VIII-A', 8, $this->smp, $this->nextYear], ['VIII-B', 8, $this->smp, $this->nextYear],
    ] as [$name, $grade, $organization, $year]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $organization, 'academic_year_id' => $year->id, 'grade' => $grade, 'name' => $name,
        ])->assertSessionHasNoErrors();
    }

    foreach ([['VII-A', ['Ahmad Fauzi', 'Aisyah Putri', 'Citra Lestari']], ['IX-A', ['Dewi Sartika']], ['VII-MTs', ['Budi Santoso']]] as [$class, $names]) {
        $this->actingAs($this->admin)->post(route('academic.class-members.store', ['class' => akhClass($class)->id]), [
            'enrollment_ids' => array_map(fn (string $name): string => akhEnrollment($this->tenant, $name)->id, $names),
            'start_date' => $this->today,
        ])->assertSessionHasNoErrors();
    }
});

function akhClass(string $name): SchoolClass
{
    return SchoolClass::query()->withoutGlobalScope('tenant')->where('name', $name)->sole();
}

function akhEnrollment(Tenant $tenant, string $name): Enrollment
{
    $membership = Membership::query()->where('tenant_id', $tenant->id)->whereHas('person', fn ($person) => $person->where('name', $name))->sole();
    $student = Student::query()->withoutGlobalScope('tenant')->where('membership_id', $membership->id)->sole();

    return Enrollment::query()->withoutGlobalScope('tenant')->where('student_id', $student->id)->sole();
}

function akhMember(Tenant $tenant, string $name, string $class): ClassMember
{
    return ClassMember::query()->withoutGlobalScope('tenant')
        ->where('class_id', akhClass($class)->id)
        ->where('enrollment_id', akhEnrollment($tenant, $name)->id)
        ->sole();
}

/**
 * @param  array<string, string>  $choices  nama siswa => pilihan
 * @return array<string, string> id keanggotaan di kelas asal => pilihan
 */
function akhPilihan(Tenant $tenant, string $class, array $choices): array
{
    $decisions = [];

    foreach ($choices as $name => $choice) {
        $decisions[akhMember($tenant, $name, $class)->id] = $choice;
    }

    return $decisions;
}

// ── Halaman ───────────────────────────────────────────────────────────────────

test('halaman: tujuan = kelas aktif tahun berikutnya bertingkat sama/lebih tinggi; bawaan naik ke tingkat +1', function () {
    $this->actingAs($this->operator)
        ->get(route('academic.classes.year-end', ['class' => akhClass('VII-A')->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/classes/year-end')
            ->where('nextYear.name', $this->nextYear->name)
            ->where('nextYear.start_date', $this->nextYear->start_date->toDateString())
            ->where('targets', [
                ['value' => akhClass('VII-R')->id, 'label' => 'VII-R', 'grade' => 7],
                ['value' => akhClass('VIII-A')->id, 'label' => 'VIII-A', 'grade' => 8],
                ['value' => akhClass('VIII-B')->id, 'label' => 'VIII-B', 'grade' => 8],
            ])
            ->where('canGraduate', true)
            ->has('rows', 3)
            ->where('rows.0.name', 'Ahmad Fauzi')
            ->where('rows.0.default', akhClass('VIII-A')->id)
            ->where('rows.0.next_class', null),
        );

    // Kelas tingkat tertinggi (tidak ada kelas lebih tinggi di tahun berikutnya) → bawaan Lulus.
    $this->actingAs($this->operator)
        ->get(route('academic.classes.year-end', ['class' => akhClass('IX-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('targets', [])->where('rows.0.default', 'lulus'));

    // Tombol di detail kelas.
    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => akhClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canYearEnd', true));
});

test('halaman: tahun ajaran berikutnya belum ada → diberi tahu; menyimpan ditolak', function () {
    $class = akhClass('VIII-A');

    $this->actingAs($this->operator)
        ->get(route('academic.classes.year-end', ['class' => $class->id]))
        ->assertInertia(fn (Assert $page) => $page->where('nextYear', null)->has('rows', 0));

    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => $class->id]), ['decisions' => [(string) Str::uuid7() => akhClass('VII-R')->id]])
        ->assertSessionHasErrors(['form' => 'Tahun ajaran sesudah '.$this->nextYear->name.' belum dibuat di kalender akademik.']);
});

// ── Proses ────────────────────────────────────────────────────────────────────

test('proses: naik, tinggal kelas, dan lulus tersimpan sekaligus; kelas lama tetap sebagai riwayat; tercatat di audit', function () {
    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => akhClass('VII-A')->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-A', [
                'Ahmad Fauzi' => akhClass('VIII-A')->id,
                'Aisyah Putri' => akhClass('VII-R')->id,
                'Citra Lestari' => 'lulus',
            ]),
            'graduation_date' => $this->today,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('academic.classes.show', ['class' => akhClass('VII-A')->id]));

    $ahmadNext = akhMember($this->tenant, 'Ahmad Fauzi', 'VIII-A');
    $citra = akhEnrollment($this->tenant, 'Citra Lestari');

    expect($ahmadNext->start_date->toDateString())->toBe($this->nextYear->start_date->toDateString())
        ->and($ahmadNext->end_date)->toBeNull()
        ->and(akhMember($this->tenant, 'Ahmad Fauzi', 'VII-A')->end_date)->toBeNull()
        ->and(akhMember($this->tenant, 'Aisyah Putri', 'VII-R')->end_date)->toBeNull()
        ->and($citra->end_date?->toDateString())->toBe($this->today)
        ->and($citra->end_reason?->value)->toBe('LULUS')
        ->and(akhMember($this->tenant, 'Citra Lestari', 'VII-A')->end_date?->toDateString())->toBe($this->today)
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::ENROLLMENT_CLOSED)->where('subject_id', $citra->id)->exists())->toBeTrue()
        ->and(json_decode((string) DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_YEAR_END_PROCESSED)->sole()->changes, true))
        ->toEqual([
            'kelas' => 'VII-A', 'tahun_ajaran' => $this->current->name, 'tahun_ajaran_tujuan' => $this->nextYear->name,
            'naik' => 1, 'tinggal' => 1, 'lulus' => 1, 'tanggal_lulus' => $this->today,
        ]);
});

test('semua atau tidak sama sekali: siswa yang sudah punya kelas tahun berikutnya disebut namanya', function () {
    // Ahmad sudah dimasukkan ke VIII-B sebelumnya.
    $this->actingAs($this->operator)->post(route('academic.class-members.store', ['class' => akhClass('VIII-B')->id]), [
        'enrollment_ids' => [akhEnrollment($this->tenant, 'Ahmad Fauzi')->id],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.year-end', ['class' => akhClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.next_class', 'VIII-B')->where('rows.0.default', 'lewati'));

    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => akhClass('VII-A')->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-A', [
                'Ahmad Fauzi' => akhClass('VIII-A')->id,
                'Aisyah Putri' => akhClass('VIII-A')->id,
                'Citra Lestari' => 'lewati',
            ]),
        ])
        ->assertSessionHasErrors(['decisions' => '1 siswa tidak bisa diproses: Ahmad Fauzi (sudah punya kelas VIII-B di tahun ajaran '.$this->nextYear->name.'). Tidak ada yang disimpan.']);

    expect(DB::table('ac_class_members')->where('class_id', akhClass('VIII-A')->id)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::CLASS_YEAR_END_PROCESSED)->exists())->toBeFalse();
});

test('ditolak: kelas tujuan bukan kelas aktif tahun berikutnya, tanggal lulus di masa depan, tidak ada yang dipilih', function () {
    $class = akhClass('VII-A');
    akhClass('VIII-B')->forceFill(['is_active' => false])->save();

    foreach ([akhClass('IX-A')->id, akhClass('VIII-B')->id] as $wrongTarget) {
        $this->actingAs($this->operator)
            ->post(route('academic.classes.year-end.store', ['class' => $class->id]), [
                'decisions' => akhPilihan($this->tenant, 'VII-A', ['Ahmad Fauzi' => $wrongTarget]),
            ])
            ->assertSessionHasErrors(['decisions' => '1 siswa tidak bisa diproses: Ahmad Fauzi (kelas tujuan tidak tersedia di tahun ajaran '.$this->nextYear->name.'). Tidak ada yang disimpan.']);
    }

    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => $class->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-A', ['Citra Lestari' => 'lulus']),
            'graduation_date' => '2999-01-01',
        ])
        ->assertSessionHasErrors(['graduation_date' => 'Tanggal lulus tidak boleh di masa depan.']);

    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => $class->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-A', ['Citra Lestari' => 'lewati']),
        ])
        ->assertSessionHasErrors(['decisions' => 'Pilih minimal satu siswa untuk diproses (naik, tinggal kelas, atau lulus).']);

    expect(akhEnrollment($this->tenant, 'Citra Lestari')->end_date)->toBeNull();
});

test('tahun ajaran kelas sudah lewat: tetap bisa diproses (kelas lama hanya dibaca)', function () {
    $this->current->forceFill(['is_current' => false, 'end_date' => BusinessClock::today()->subDay()->toDateString()])->save();

    $this->actingAs($this->operator)
        ->get(route('academic.classes.show', ['class' => akhClass('VII-A')->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('canYearEnd', true));

    $this->actingAs($this->operator)
        ->post(route('academic.classes.year-end.store', ['class' => akhClass('VII-A')->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-A', ['Ahmad Fauzi' => akhClass('VIII-A')->id]),
        ])
        ->assertSessionHasNoErrors();

    expect(akhMember($this->tenant, 'Ahmad Fauzi', 'VIII-A')->end_date)->toBeNull();
});

test('hak akses: lembaga di luar cakupan 404, tanpa izin kelola kelas 403', function () {
    $mtsClass = akhClass('VII-MTs');
    $kepala = DevAccounts::user('kepala.unit1');

    $this->actingAs($this->operator)->get(route('academic.classes.year-end', ['class' => $mtsClass->id]))->assertNotFound();
    $this->actingAs($kepala)->get(route('academic.classes.year-end', ['class' => $mtsClass->id]))->assertForbidden();
    $this->actingAs($kepala)
        ->post(route('academic.classes.year-end.store', ['class' => $mtsClass->id]), [
            'decisions' => akhPilihan($this->tenant, 'VII-MTs', ['Budi Santoso' => 'lulus']),
            'graduation_date' => $this->today,
        ])
        ->assertForbidden();
    $this->actingAs($kepala)
        ->get(route('academic.classes.show', ['class' => $mtsClass->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canYearEnd', false));

    expect(akhEnrollment($this->tenant, 'Budi Santoso')->end_date)->toBeNull();
});
