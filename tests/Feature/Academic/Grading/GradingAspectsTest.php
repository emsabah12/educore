<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Grading\GradingRules;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Application\Grading\ManageGrades;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;
use Tests\TestCase;

/*
 * Aspek penilaian (PRD-002 §22 AC-8a): aturan berisi aspek (Pengetahuan & Praktik) dengan
 * komponen sendiri; mapel memilih aspek yang dinilai; nilai akhir = rata-rata aspek, 1 desimal.
 *
 * "Hari ini" 22 Okt 2026 → Semester Ganjil 2026/2027. MA Unit 1, kelas X-A berisi Ahmad & Aisyah.
 * Aturan MA: KKM 60, A ≥ 90, B ≥ 80; Pengetahuan (UTS 50, UAS 50) dan Praktik (PRAKTIK 100).
 * Mapel Nahwu (semua aspek) dan Mutholaah (Praktik saja).
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

    $this->actingAs($this->admin)->post(route('academic.grading.assign'), aspForm($this->ma))->assertSessionHasNoErrors();

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

    foreach (['Nahwu', 'Mutholaah'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name])->assertSessionHasNoErrors();
    }

    $this->subjectIds = DB::table('ac_subjects')->where('organization_id', $this->ma)->pluck('id', 'name')->map(fn ($id) => (string) $id)->all();

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), [
        'subject_ids' => array_values($this->subjectIds),
    ])->assertSessionHasNoErrors();

    $this->rows = DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->pluck('cs.id', 's.name')->map(fn ($id) => (string) $id)->all();
    $this->enrollments = DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->pluck('e.id', 'p.name')->map(fn ($id) => (string) $id)->all();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aspForm(string $organizationId, array $overrides = []): array
{
    return array_merge([
        'target' => 'lembaga', 'organization_id' => $organizationId, 'kkm' => '60', 'grade_a' => '90', 'grade_b' => '80',
        'aspects' => [
            ['code' => 'tulis', 'name' => ' Pengetahuan ', 'components' => [
                ['code' => 'UTS', 'name' => 'Ujian tengah', 'weight' => '50'],
                ['code' => 'UAS', 'name' => 'Ujian akhir', 'weight' => '50'],
            ]],
            ['code' => 'PRAKTIK', 'name' => 'Praktik', 'components' => [
                ['code' => 'PRAKTIK', 'name' => 'Ujian praktik', 'weight' => '100'],
            ]],
        ],
    ], $overrides);
}

/**
 * @param  list<string>  $aspects
 */
function aspSetAspects(TestCase $test, mixed $user, string $subjectId, string $name, array $aspects): TestResponse
{
    return $test->actingAs($user)->patch(route('academic.subjects.update', ['subject' => $subjectId]), [
        'name' => $name, 'grading_aspects_set' => '1', 'grading_aspects' => $aspects,
    ]);
}

/**
 * @param  array<string, array<string, string>>  $scores  id pendaftaran => kode komponen => nilai
 */
function aspSave(TestCase $test, mixed $user, string $classSubject, string $term, array $scores): TestResponse
{
    $grades = [];

    foreach ($scores as $enrollment => $values) {
        $grades[$enrollment] = ['scores' => $values, 'description' => ''];
    }

    return $test->actingAs($user)->post(route('academic.grades.store', ['classSubject' => $classSubject]), ['semester' => $term, 'grades' => $grades]);
}

test('aturan beraspek: tersimpan per lembaga dengan ringkasan per aspek; bobot per aspek & kode komponen unik diperiksa; format lama = satu aspek', function () {
    $rules = app(GradingSetting::class)->rulesFor($this->tenant->id, $this->ma);

    expect($rules->toArray())->toBe([
        'kkm' => 60, 'grade_a' => 90, 'grade_b' => 80,
        'aspects' => [
            ['code' => 'TULIS', 'name' => 'Pengetahuan', 'components' => [
                ['code' => 'UTS', 'name' => 'Ujian tengah', 'weight' => 50],
                ['code' => 'UAS', 'name' => 'Ujian akhir', 'weight' => 50],
            ]],
            ['code' => 'PRAKTIK', 'name' => 'Praktik', 'components' => [
                ['code' => 'PRAKTIK', 'name' => 'Ujian praktik', 'weight' => 100],
            ]],
        ],
    ])
        ->and($rules->hasAspects())->toBeTrue()
        ->and($rules->summary())->toBe('KKM 60 · A ≥ 90, B ≥ 80 · Pengetahuan: Ujian tengah 50%, Ujian akhir 50%; Praktik: Ujian praktik 100%')
        ->and($rules->forAspects(['PRAKTIK'])->aspectCodes())->toBe(['PRAKTIK'])
        ->and($rules->forAspects(['LISAN'])->aspectCodes())->toBe(['TULIS', 'PRAKTIK']);

    $form = aspForm($this->ma);
    $form['aspects'][1]['components'][0]['weight'] = '90';
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['aspects' => 'Jumlah bobot komponen aspek Praktik harus 100 (sekarang 90).']);

    $form = aspForm($this->ma);
    $form['aspects'][1]['components'][0]['code'] = 'UTS';
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['aspects' => 'Kode komponen UTS dipakai lebih dari sekali.']);

    $form = aspForm($this->ma);
    $form['aspects'][] = $form['aspects'][1];
    $form['aspects'][] = $form['aspects'][1];
    $this->actingAs($this->admin)->post(route('academic.grading.assign'), $form)
        ->assertSessionHasErrors(['aspects' => 'Paling banyak 3 aspek penilaian.']);

    $legacy = GradingRules::fromValue(['kkm' => 70, 'grade_a' => 90, 'grade_b' => 80, 'components' => [['code' => 'PAS', 'name' => 'PAS', 'weight' => 100]]]);
    expect($legacy->aspectCodes())->toBe([GradingRules::DEFAULT_ASPECT_CODE])
        ->and($legacy->hasAspects())->toBeFalse()
        ->and($legacy->summary())->toBe('KKM 70 · A ≥ 90, B ≥ 80 · PAS 100%');
});

test('aspek per mapel: pilihan tampil bila lembaga beraspek; tersimpan; semua = null; kosong/tidak dikenal ditolak; ubah tanpa pilihan tidak mengubah', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.subjects.index', ['lembaga' => $this->ma]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('aspects', [['code' => 'TULIS', 'name' => 'Pengetahuan'], ['code' => 'PRAKTIK', 'name' => 'Praktik']])
            ->where('subjects.0.grading_aspects', null),
        );

    $mutholaah = $this->subjectIds['Mutholaah'];
    aspSetAspects($this, $this->admin, $mutholaah, 'Mutholaah', ['praktik'])->assertSessionHasNoErrors();
    expect(json_decode((string) DB::table('ac_subjects')->where('id', $mutholaah)->value('grading_aspects'), true))->toBe(['PRAKTIK']);

    aspSetAspects($this, $this->admin, $this->subjectIds['Nahwu'], 'Nahwu', ['PRAKTIK', 'TULIS'])->assertSessionHasNoErrors();
    expect(DB::table('ac_subjects')->where('id', $this->subjectIds['Nahwu'])->value('grading_aspects'))->toBeNull();

    aspSetAspects($this, $this->admin, $mutholaah, 'Mutholaah', [])
        ->assertSessionHasErrors(['grading_aspects' => 'Pilih minimal satu aspek yang dinilai.']);
    aspSetAspects($this, $this->admin, $mutholaah, 'Mutholaah', ['LISAN'])
        ->assertSessionHasErrors(['grading_aspects' => 'Aspek LISAN tidak ada di aturan penilaian lembaga ini. Muat ulang halaman.']);

    $this->actingAs($this->admin)->patch(route('academic.subjects.update', ['subject' => $mutholaah]), ['name' => 'Muthola\'ah'])->assertSessionHasNoErrors();
    expect(json_decode((string) DB::table('ac_subjects')->where('id', $mutholaah)->value('grading_aspects'), true))->toBe(['PRAKTIK']);
});

test('lembar nilai beraspek: nilai aspek & rata-rata 1 desimal; mapel praktik saja; finalkan wajib semua aspek', function () {
    aspSetAspects($this, $this->admin, $this->subjectIds['Mutholaah'], 'Mutholaah', ['PRAKTIK'])->assertSessionHasNoErrors();
    $ahmad = $this->enrollments['Ahmad Fauzi'];
    $aisyah = $this->enrollments['Aisyah Putri'];

    aspSave($this, $this->admin, $this->rows['Nahwu'], $this->term->id, [
        $ahmad => ['UTS' => '98', 'UAS' => '98', 'PRAKTIK' => '75'],
        $aisyah => ['UTS' => '90', 'UAS' => '91', 'PRAKTIK' => ''],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->rows['Nahwu'], 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sheet.rules.aspects', 2)
            ->where('sheet.students.0.name', 'Ahmad Fauzi')
            ->where('sheet.students.0.aspects', ['TULIS' => 98, 'PRAKTIK' => 75])
            ->where('sheet.students.0.final', 86.5)
            ->where('sheet.students.0.predicate', 'B')
            ->where('sheet.students.1.aspects', ['TULIS' => 91, 'PRAKTIK' => null])
            ->where('sheet.students.1.final', null),
        );

    expect((float) DB::table('ac_grades')->where('enrollment_id', $ahmad)->value('final_score'))->toBe(86.5);

    $this->actingAs($this->admin)
        ->post(route('academic.grades.finalize', ['classSubject' => $this->rows['Nahwu']]), ['semester' => $this->term->id])
        ->assertSessionHasErrors(['form' => 'Nilai belum lengkap untuk 1 siswa: Aisyah Putri. Lengkapi semua komponen lalu simpan dulu.']);

    aspSave($this, $this->admin, $this->rows['Mutholaah'], $this->term->id, [
        $ahmad => ['PRAKTIK' => '75', 'UTS' => '10'],
        $aisyah => ['PRAKTIK' => '85'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.grades.show', ['classSubject' => $this->rows['Mutholaah'], 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.rules.aspects.0.code', 'PRAKTIK')
            ->has('sheet.rules.aspects', 1)
            ->where('sheet.students.0.scores', ['PRAKTIK' => 75])
            ->where('sheet.students.0.final', 75),
        );

    $this->actingAs($this->admin)
        ->post(route('academic.grades.finalize', ['classSubject' => $this->rows['Mutholaah']]), ['semester' => $this->term->id])
        ->assertSessionHasNoErrors();

    $three = new GradingRules(75, 90, 80, [
        ['code' => 'A', 'name' => 'A', 'components' => [['code' => 'X', 'name' => 'X', 'weight' => 100]]],
        ['code' => 'B', 'name' => 'B', 'components' => [['code' => 'Y', 'name' => 'Y', 'weight' => 100]]],
        ['code' => 'C', 'name' => 'C', 'components' => [['code' => 'Z', 'name' => 'Z', 'weight' => 100]]],
    ]);
    expect(ManageGrades::finalScore($three, ['X' => 85.0, 'Y' => 86.0, 'Z' => 86.0]))->toBe(85.7)
        ->and(ManageGrades::finalScore($three, ['X' => 85.0, 'Y' => 86.0, 'Z' => null]))->toBeNull();
});

test('rapor, leger & unduhan: kolom per aspek, aspek yang tidak dinilai kosong, jumlah & rata-rata per aspek', function () {
    aspSetAspects($this, $this->admin, $this->subjectIds['Mutholaah'], 'Mutholaah', ['PRAKTIK'])->assertSessionHasNoErrors();
    $ahmad = $this->enrollments['Ahmad Fauzi'];
    $aisyah = $this->enrollments['Aisyah Putri'];

    aspSave($this, $this->admin, $this->rows['Nahwu'], $this->term->id, [
        $ahmad => ['UTS' => '98', 'UAS' => '98', 'PRAKTIK' => '75'],
        $aisyah => ['UTS' => '90', 'UAS' => '90', 'PRAKTIK' => '80'],
    ])->assertSessionHasNoErrors();
    aspSave($this, $this->admin, $this->rows['Mutholaah'], $this->term->id, [$ahmad => ['PRAKTIK' => '75'], $aisyah => ['PRAKTIK' => '85']])->assertSessionHasNoErrors();

    foreach (['Nahwu', 'Mutholaah'] as $subject) {
        $this->actingAs($this->admin)
            ->post(route('academic.grades.finalize', ['classSubject' => $this->rows[$subject]]), ['semester' => $this->term->id])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => $ahmad]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.multi_aspect', true)
            ->where('report.aspects', [['code' => 'PRAKTIK', 'name' => 'Praktik'], ['code' => 'TULIS', 'name' => 'Pengetahuan']])
            ->where('report.cards.0.subjects.0.name', 'Mutholaah')
            ->where('report.cards.0.subjects.0.aspects', ['PRAKTIK' => ['score' => 75, 'predicate' => 'C']])
            ->where('report.cards.0.subjects.0.final', 75)
            ->where('report.cards.0.subjects.1.aspects', ['TULIS' => ['score' => 98, 'predicate' => 'A'], 'PRAKTIK' => ['score' => 75, 'predicate' => 'C']])
            ->where('report.cards.0.subjects.1.final', 86.5)
            ->where('report.cards.0.subjects.1.predicate', 'B')
            ->where('report.cards.0.total', 161.5)
            ->where('report.cards.0.average', 80.8)
            ->where('report.cards.0.aspect_totals', ['PRAKTIK' => ['total' => 150, 'average' => 75], 'TULIS' => ['total' => 98, 'average' => 98]]),
        );

    $this->actingAs($this->admin)
        ->get(route('academic.ledger.show', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ledger.multi_aspect', true)
            ->where('ledger.students.0.grades', [$this->rows['Mutholaah'] => 75, $this->rows['Nahwu'] => 86.5])
            ->where('ledger.students.0.aspect_grades', [])
            ->where('ledger.students.1.average', 85),
        );

    $response = $this->actingAs($this->admin)
        ->get(route('academic.ledger.export', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();
    @unlink($path);

    expect(array_keys($sheets))->toBe(['Leger', 'Per aspek'])
        ->and($sheets['Per aspek'][0])->toBe(['No', 'Nama', 'NISN', 'Nomor induk', 'Mutholaah — Praktik', 'Nahwu — Pengetahuan', 'Nahwu — Praktik'])
        ->and([$sheets['Per aspek'][1][1], (int) $sheets['Per aspek'][1][4], (int) $sheets['Per aspek'][1][5], (int) $sheets['Per aspek'][1][6]])->toBe(['Ahmad Fauzi', 75, 98, 75])
        ->and((float) $sheets['Leger'][2][5])->toBe(86.5);
});
