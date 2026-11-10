<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Kelompok mapel, skala predikat & nilai huruf (PRD-002 §22.4 AC-8b).
 *
 * "Hari ini" 22 Okt 2026 → Semester Ganjil 2026/2027. MA Unit 1, kelas X-A berisi Ahmad & Aisyah.
 * Aturan MA: KKM 60, skala 5 tingkat bersebutan; aspek Pengetahuan (TULIS 100) & Praktik (PRAKTIK 100).
 * Kelompok: 1 Pelajaran Madrasah (ikut rata-rata) berisi Nahwu & Fiqih; 2 Kepesantrenan (tidak ikut)
 * berisi Bahasa Arab (angka) & Riyadhah (huruf, praktik saja).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->term = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->where('sequence', 1)->sole();

    $this->actingAs($this->admin)->post(route('academic.grading.assign'), sgForm($this->ma))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->sole();

    foreach ([['Ahmad Fauzi', 'L'], ['Aisyah Putri', 'P']] as [$name, $gender]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => $gender, 'organization_id' => $this->ma, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'class_id' => $this->class->id,
        ])->assertSessionHasNoErrors();
    }

    $this->enrollments = DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->pluck('e.id', 'p.name')->map(fn ($id) => (string) $id)->all();
});

/**
 * @return array<string, mixed>
 */
function sgForm(string $organizationId): array
{
    return [
        'target' => 'lembaga', 'organization_id' => $organizationId, 'kkm' => '60',
        'predicates' => [
            ['letter' => 'a', 'label' => ' Sangat  Baik ', 'min' => '90'],
            ['letter' => 'B', 'label' => 'Baik', 'min' => '75'],
            ['letter' => 'C', 'label' => 'Cukup Baik', 'min' => '60'],
            ['letter' => 'D', 'label' => 'Kurang Baik', 'min' => '40'],
            ['letter' => 'E', 'label' => 'Perlu Perbaikan', 'min' => '25'],
        ],
        'aspects' => [
            ['code' => 'TULIS', 'name' => 'Pengetahuan', 'components' => [['code' => 'TULIS', 'name' => 'Ujian tulis', 'weight' => '100']]],
            ['code' => 'PRAKTIK', 'name' => 'Praktik', 'components' => [['code' => 'PRAKTIK', 'name' => 'Ujian praktik', 'weight' => '100']]],
        ],
    ];
}

/**
 * Buat kelompok, kembalikan id-nya.
 */
function sgGroup(TestCase $test, mixed $user, string $organizationId, string $name, int $order, bool $counts): string
{
    $test->actingAs($user)->post(route('academic.subject-groups.store'), [
        'organization_id' => $organizationId, 'name' => $name, 'sort_order' => $order, 'counts_in_totals' => $counts ? '1' : '0',
    ])->assertSessionHasNoErrors();

    return (string) DB::table('ac_subject_groups')->where('name', $name)->value('id');
}

/**
 * Buat mapel dengan kelompok/urutan/jenis nilai/aspek; kembalikan id mapel.
 *
 * @param  list<string>|null  $aspects
 */
function sgSubject(TestCase $test, mixed $user, string $organizationId, string $name, ?string $groupId, ?int $order, string $type = 'NUMBER', ?array $aspects = null): string
{
    $test->actingAs($user)->post(route('academic.subjects.store'), array_filter([
        'organization_id' => $organizationId, 'name' => $name, 'group_id' => $groupId ?? '', 'sort_order' => $order ?? '', 'grade_type' => $type,
        'grading_aspects_set' => $aspects === null ? null : '1', 'grading_aspects' => $aspects,
    ], fn ($value) => $value !== null))->assertSessionHasNoErrors();

    return (string) DB::table('ac_subjects')->where('name', $name)->value('id');
}

/**
 * @param  array<string, array<string, mixed>>  $grades  id pendaftaran => isian
 */
function sgSave(TestCase $test, mixed $user, string $classSubject, string $term, array $grades): TestResponse
{
    return $test->actingAs($user)->post(route('academic.grades.store', ['classSubject' => $classSubject]), ['semester' => $term, 'grades' => $grades]);
}

test('skala predikat: 5 tingkat bersebutan tersimpan rapi, tingkat terakhir 0; urutan & huruf diperiksa; aturan lama tetap A–D', function () {
    $rules = app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma);

    expect($rules->predicates)->toBe([
        ['letter' => 'A', 'label' => 'Sangat Baik', 'min' => 90],
        ['letter' => 'B', 'label' => 'Baik', 'min' => 75],
        ['letter' => 'C', 'label' => 'Cukup Baik', 'min' => 60],
        ['letter' => 'D', 'label' => 'Kurang Baik', 'min' => 40],
        ['letter' => 'E', 'label' => 'Perlu Perbaikan', 'min' => 0],
    ])
        ->and($rules->predicate(98))->toBe('A')
        ->and($rules->predicate(88))->toBe('B')
        ->and($rules->predicate(70))->toBe('C')
        ->and($rules->predicate(10))->toBe('E')
        ->and($rules->labelOf('B'))->toBe('Baik')
        ->and($rules->hasLabels())->toBeTrue()
        ->and($rules->scaleSummary())->toBe('A Sangat Baik ≥ 90, B Baik ≥ 75, C Cukup Baik ≥ 60, D Kurang Baik ≥ 40, E Perlu Perbaikan < 40');

    $form = sgForm($this->ma);
    $form['predicates'][2]['min'] = '80';
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['predicates' => 'Nilai minimal predikat C harus lebih kecil dari tingkat di atasnya (75).']);

    $form = sgForm($this->ma);
    $form['predicates'][1]['letter'] = 'A';
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['predicates' => 'Huruf predikat A dipakai lebih dari sekali.']);

    $form = sgForm($this->ma);
    $form['predicates'] = [['letter' => 'A', 'label' => '', 'min' => '0']];
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['predicates' => 'Isi minimal 2 tingkat predikat.']);

    $other = app(GradingSetting::class)->rulesFor($this->tenant->id, CoreFixtures::node($this->tenant, 'U1-MDA')->id);
    expect($other->letters())->toBe(['A', 'B', 'C', 'D'])->and($other->hasLabels())->toBeFalse();
});

test('kelompok mapel: tambah/ubah/hapus, nama unik, mapel diurut per kelompok, kelompok terpakai tidak bisa dihapus', function () {
    $madrasah = sgGroup($this, $this->admin, $this->ma, 'Pelajaran Madrasah', 1, true);
    $pesantren = sgGroup($this, $this->admin, $this->ma, 'Kepesantrenan', 2, false);

    $this->actingAs($this->admin)->post(route('academic.subject-groups.store'), ['organization_id' => $this->ma, 'name' => 'pelajaran madrasah'])
        ->assertSessionHasErrors(['name' => 'Kelompok pelajaran madrasah sudah ada di lembaga ini.']);

    sgSubject($this, $this->admin, $this->ma, 'Riyadhah', $pesantren, 1, 'LETTER');
    sgSubject($this, $this->admin, $this->ma, 'Nahwu', $madrasah, 1);
    sgSubject($this, $this->admin, $this->ma, 'Akhlak', null, null);
    sgSubject($this, $this->admin, $this->ma, 'Fiqih', $madrasah, 2);

    $this->actingAs($this->admin)
        ->get(route('academic.subjects.index', ['lembaga' => $this->ma]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.name', 'Pelajaran Madrasah')
            ->where('groups.0.subject_count', 2)
            ->where('groups.1.counts_in_totals', false)
            ->where('subjects.0.name', 'Nahwu')
            ->where('subjects.1.name', 'Fiqih')
            ->where('subjects.2.name', 'Riyadhah')
            ->where('subjects.2.group', 'Kepesantrenan')
            ->where('subjects.2.grade_type', 'LETTER')
            ->where('subjects.3.name', 'Akhlak')
            ->where('subjects.3.group', null),
        );

    $this->actingAs($this->admin)->patch(route('academic.subject-groups.update', ['group' => $pesantren]), ['name' => 'Pelajaran Kepesantrenan', 'sort_order' => 3, 'counts_in_totals' => '0'])
        ->assertSessionHasNoErrors();
    expect(DB::table('ac_subject_groups')->where('id', $pesantren)->value('name'))->toBe('Pelajaran Kepesantrenan');

    $this->actingAs($this->admin)->delete(route('academic.subject-groups.destroy', ['group' => $madrasah]))
        ->assertSessionHasErrors(['form' => 'Kelompok Pelajaran Madrasah masih dipakai mata pelajaran. Pindahkan mapelnya dulu.']);

    // Kelompok yang tidak ada di lembaga mapel ditolak; mengubah mapel tanpa jenis nilai tidak mengubah kelompoknya.
    $nahwu = (string) DB::table('ac_subjects')->where('name', 'Nahwu')->value('id');
    $this->actingAs($this->admin)->patch(route('academic.subjects.update', ['subject' => $nahwu]), ['name' => 'Nahwu', 'group_id' => (string) Str::uuid(), 'grade_type' => 'NUMBER'])
        ->assertSessionHasErrors(['group_id' => 'Kelompok tidak ditemukan di lembaga ini. Muat ulang halaman.']);
    $this->actingAs($this->admin)->patch(route('academic.subjects.update', ['subject' => $nahwu]), ['name' => 'Nahwu Sharaf'])->assertSessionHasNoErrors();
    expect(DB::table('ac_subjects')->where('id', $nahwu)->value('group_id'))->toBe($madrasah);

    $empty = sgGroup($this, $this->admin, $this->ma, 'Kosong', 9, true);
    $this->actingAs($this->admin)->delete(route('academic.subject-groups.destroy', ['group' => $empty]))->assertSessionHasNoErrors();
    expect(DB::table('ac_subject_groups')->where('id', $empty)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::SUBJECT_GROUP_DELETED)->exists())->toBeTrue();
});

test('nilai huruf: pilih huruf skala per aspek, huruf asing ditolak, finalkan wajib lengkap', function () {
    $riyadhah = sgSubject($this, $this->admin, $this->ma, 'Riyadhah', null, null, 'LETTER', ['PRAKTIK']);
    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), ['subject_ids' => [$riyadhah]])->assertSessionHasNoErrors();
    $row = (string) DB::table('ac_class_subjects')->value('id');
    $ahmad = $this->enrollments['Ahmad Fauzi'];
    $aisyah = $this->enrollments['Aisyah Putri'];

    sgSave($this, $this->admin, $row, $this->term->id, [$ahmad => ['letters' => ['PRAKTIK' => 'Z'], 'description' => '']])
        ->assertSessionHasErrors(['grades' => 'Nilai untuk Ahmad Fauzi harus salah satu dari: A, B, C, D, E.']);

    sgSave($this, $this->admin, $row, $this->term->id, [
        $ahmad => ['letters' => ['PRAKTIK' => 'b'], 'scores' => ['PRAKTIK' => '90'], 'description' => 'Rajin'],
        $aisyah => ['letters' => ['PRAKTIK' => ''], 'description' => ''],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $row, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.grade_type', 'LETTER')
            ->where('sheet.complete', 1)
            ->where('sheet.students.0.letters', ['PRAKTIK' => 'B'])
            ->where('sheet.students.0.final', null)
            ->where('sheet.students.1.letters', ['PRAKTIK' => null]),
        );

    expect(DB::table('ac_grades')->where('enrollment_id', $ahmad)->value('final_score'))->toBeNull();

    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $row]), ['semester' => $this->term->id])
        ->assertSessionHasErrors(['form' => 'Nilai belum lengkap untuk 1 siswa: Aisyah Putri. Pilih huruf semua aspek lalu simpan dulu.']);

    sgSave($this, $this->admin, $row, $this->term->id, [$aisyah => ['letters' => ['PRAKTIK' => 'A'], 'description' => '']])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $row]), ['semester' => $this->term->id])->assertSessionHasNoErrors();
});

test('rapor & leger: kelompok bernomor, sebutan predikat, huruf tidak masuk rata-rata, kelompok tak ikut rata-rata dikecualikan', function () {
    $madrasah = sgGroup($this, $this->admin, $this->ma, 'Pelajaran Madrasah', 1, true);
    $pesantren = sgGroup($this, $this->admin, $this->ma, 'Pelajaran Kepesantrenan', 2, false);
    $ids = [
        sgSubject($this, $this->admin, $this->ma, 'Nahwu', $madrasah, 1),
        sgSubject($this, $this->admin, $this->ma, 'Fiqih', $madrasah, 2),
        sgSubject($this, $this->admin, $this->ma, 'Bahasa Arab', $pesantren, 2),
        sgSubject($this, $this->admin, $this->ma, 'Riyadhah', $pesantren, 1, 'LETTER', ['PRAKTIK']),
    ];
    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), ['subject_ids' => $ids])->assertSessionHasNoErrors();
    $rows = DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->pluck('cs.id', 's.name')->map(fn ($id) => (string) $id)->all();
    $ahmad = $this->enrollments['Ahmad Fauzi'];
    $aisyah = $this->enrollments['Aisyah Putri'];

    $scores = [
        'Nahwu' => [$ahmad => [98, 75], $aisyah => [90, 80]],
        'Fiqih' => [$ahmad => [95, 80], $aisyah => [85, 85]],
        'Bahasa Arab' => [$ahmad => [80, 85], $aisyah => [70, 70]],
    ];

    foreach ($scores as $subject => $students) {
        $grades = [];

        foreach ($students as $enrollment => [$written, $practice]) {
            $grades[$enrollment] = ['scores' => ['TULIS' => (string) $written, 'PRAKTIK' => (string) $practice], 'description' => ''];
        }

        sgSave($this, $this->admin, $rows[$subject], $this->term->id, $grades)->assertSessionHasNoErrors();
    }

    sgSave($this, $this->admin, $rows['Riyadhah'], $this->term->id, [
        $ahmad => ['letters' => ['PRAKTIK' => 'B'], 'description' => ''],
        $aisyah => ['letters' => ['PRAKTIK' => 'A'], 'description' => ''],
    ])->assertSessionHasNoErrors();

    foreach ($rows as $row) {
        $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $row]), ['semester' => $this->term->id])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => $ahmad]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.grouped', true)
            ->where('report.labels', true)
            ->has('report.predicates', 5)
            ->where('report.cards.0.subjects.0.name', 'Nahwu')
            ->where('report.cards.0.subjects.0.group', 'Pelajaran Madrasah')
            ->where('report.cards.0.subjects.0.final', 86.5)
            ->where('report.cards.0.subjects.0.label', 'Baik')
            ->where('report.cards.0.subjects.0.aspects.TULIS', ['score' => 98, 'predicate' => 'A', 'label' => 'Sangat Baik'])
            ->where('report.cards.0.subjects.1.name', 'Fiqih')
            ->where('report.cards.0.subjects.2.name', 'Riyadhah')
            ->where('report.cards.0.subjects.2.grade_type', 'LETTER')
            ->where('report.cards.0.subjects.2.final', null)
            ->where('report.cards.0.subjects.2.predicate', 'B')
            ->where('report.cards.0.subjects.2.label', 'Baik')
            ->where('report.cards.0.subjects.2.aspects', ['PRAKTIK' => ['score' => null, 'predicate' => 'B', 'label' => 'Baik']])
            ->where('report.cards.0.subjects.3.name', 'Bahasa Arab')
            ->where('report.cards.0.subjects.3.counts', false)
            // Hanya Nahwu & Fiqih: tulis 98+95, praktik 75+80; rata-rata = (96,5 + 77,5) ÷ 2.
            ->where('report.cards.0.aspect_totals', ['TULIS' => ['total' => 193, 'average' => 96.5], 'PRAKTIK' => ['total' => 155, 'average' => 77.5]])
            ->where('report.cards.0.total', 174)
            ->where('report.cards.0.average', 87),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.ledger.show', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ledger.subjects.2.name', 'Riyadhah')
            ->where('ledger.subjects.2.grade_type', 'LETTER')
            ->where('ledger.students.0.grades.'.$rows['Riyadhah'], 'B')
            ->where('ledger.students.0.grades.'.$rows['Bahasa Arab'], 82.5)
            // Rata-rata siswa hanya Nahwu 86,5 & Fiqih 87,5.
            ->where('ledger.students.0.average', 87)
            ->where('ledger.students.1.below', 0),
        );
});
