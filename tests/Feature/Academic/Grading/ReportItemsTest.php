<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Grading\ReportCards;
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

/*
 * Ekstrakurikuler, kepribadian & peringkat kelas (PRD-002 §22.6 AC-8d).
 *
 * "Hari ini" 22 Okt 2026 → Semester Ganjil. MA Unit 1: skala 5 tingkat (A Sangat Baik … E), satu
 * komponen PAS 100. Kelas X-A berisi Ahmad, Aisyah, Budi; mapel Fiqih.
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

    $this->actingAs($this->admin)->post(route('academic.grading.assign'), [
        'target' => 'lembaga', 'organization_id' => $this->ma, 'kkm' => '60',
        'predicates' => [
            ['letter' => 'A', 'label' => 'Sangat Baik', 'min' => '90'],
            ['letter' => 'B', 'label' => 'Baik', 'min' => '75'],
            ['letter' => 'C', 'label' => 'Cukup Baik', 'min' => '60'],
            ['letter' => 'D', 'label' => 'Kurang Baik', 'min' => '40'],
            ['letter' => 'E', 'label' => 'Perlu Perbaikan', 'min' => '0'],
        ],
        'components' => [['code' => 'PAS', 'name' => 'PAS', 'weight' => '100']],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(route('academic.classes.store'), [
        'organization_id' => $this->ma, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => 'X-A',
    ])->assertSessionHasNoErrors();
    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->sole();

    foreach ([['Budi Santoso', 'L'], ['Aisyah Putri', 'P'], ['Ahmad Fauzi', 'L']] as [$name, $gender]) {
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

function riItem(mixed $test, string $organizationId, string $kind, string $name, int $order = 1): string
{
    $test->actingAs($test->admin)->post(route('academic.report-items.store', ['organization' => $organizationId]), [
        'kind' => $kind, 'name' => $name, 'sort_order' => $order,
    ])->assertSessionHasNoErrors();

    return (string) DB::table('ac_report_items')->where('name', $name)->value('id');
}

test('kelola butir: ekstrakurikuler & kepribadian, contoh 6 aspek berketerangan per huruf, nama unik, hapus hanya bila belum dinilai', function () {
    $rebana = riItem($this, $this->ma, 'EKSKUL', 'Rebana');

    $this->actingAs($this->admin)->post(route('academic.report-items.store', ['organization' => $this->ma]), ['kind' => 'EKSKUL', 'name' => 'rebana'])
        ->assertSessionHasErrors(['name' => 'rebana sudah ada di daftar ini.']);

    $this->actingAs($this->admin)->post(route('academic.report-items.examples', ['organization' => $this->ma]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.report-items.examples', ['organization' => $this->ma]))->assertSessionHasNoErrors();
    expect(DB::table('ac_report_items')->where('kind', 'KEPRIBADIAN')->count())->toBe(6);

    $kelakuan = (string) DB::table('ac_report_items')->where('name', 'Kelakuan')->value('id');
    $this->actingAs($this->admin)->patch(route('academic.report-items.update', ['item' => $kelakuan]), [
        'name' => 'Kelakuan', 'sort_order' => 1, 'is_active' => '1',
        'descriptions' => ['a' => ' Sangat  sopan ', 'B' => 'Kepribadian baik, sopan, dan jujur', 'C' => ''],
    ])->assertSessionHasNoErrors();
    expect(json_decode((string) DB::table('ac_report_items')->where('id', $kelakuan)->value('descriptions'), true))
        ->toBe(['A' => 'Sangat sopan', 'B' => 'Kepribadian baik, sopan, dan jujur']);

    $this->actingAs($this->admin)
        ->get(route('academic.report-formats.index', ['lembaga' => $this->ma]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 7)
            ->where('items.0.name', 'Rebana')
            ->where('items.1.name', 'Kelakuan')
            ->has('predicates', 5),
        );

    // Sudah dinilai → tidak bisa dihapus.
    $this->actingAs($this->admin)->post(route('academic.reports.items.save', ['class' => $this->class->id]), [
        'semester' => $this->term->id, 'grades' => [$this->enrollments['Ahmad Fauzi'] => [$rebana => ['letter' => 'B']]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete(route('academic.report-items.destroy', ['item' => $rebana]))
        ->assertSessionHasErrors(['form' => 'Rebana sudah pernah dinilai, jadi tidak bisa dihapus. Nonaktifkan saja.']);

    $pramuka = riItem($this, $this->ma, 'EKSKUL', 'Pramuka', 2);
    $this->actingAs($this->admin)->delete(route('academic.report-items.destroy', ['item' => $pramuka]))->assertSessionHasNoErrors();
    expect(DB::table('ac_report_items')->where('id', $pramuka)->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', AcademicAuditAction::REPORT_ITEM_DELETED)->exists())->toBeTrue();

    // Operator lembaga lain tidak bisa menambah butir MA.
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))
        ->post(route('academic.report-items.store', ['organization' => $this->ma]), ['kind' => 'EKSKUL', 'name' => 'Kaligrafi'])
        ->assertNotFound();
});

test('isi nilai: huruf skala, kosong = tidak ikut/dihapus, keterangan kepribadian otomatis per huruf atau diganti per siswa', function () {
    $rebana = riItem($this, $this->ma, 'EKSKUL', 'Rebana');
    $pramuka = riItem($this, $this->ma, 'EKSKUL', 'Pramuka', 2);
    $this->actingAs($this->admin)->post(route('academic.report-items.examples', ['organization' => $this->ma]))->assertSessionHasNoErrors();
    $kelakuan = (string) DB::table('ac_report_items')->where('name', 'Kelakuan')->value('id');
    $kerajinan = (string) DB::table('ac_report_items')->where('name', 'Kerajinan')->value('id');
    $ahmad = $this->enrollments['Ahmad Fauzi'];
    $aisyah = $this->enrollments['Aisyah Putri'];

    $this->actingAs($this->admin)->post(route('academic.reports.items.save', ['class' => $this->class->id]), [
        'semester' => $this->term->id, 'grades' => [$ahmad => [$rebana => ['letter' => 'Z']]],
    ])->assertSessionHasErrors(['grades' => 'Nilai untuk Ahmad Fauzi harus salah satu dari: A, B, C, D, E.']);

    $this->actingAs($this->admin)->post(route('academic.reports.items.save', ['class' => $this->class->id]), [
        'semester' => $this->term->id,
        'grades' => [
            $ahmad => [$rebana => ['letter' => 'b'], $pramuka => ['letter' => ''], $kelakuan => ['letter' => 'B'], $kerajinan => ['letter' => 'A', 'note' => 'Rajin membantu teman']],
            $aisyah => [$rebana => ['letter' => 'A'], $kelakuan => ['letter' => 'A']],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('ac_report_item_grades')->count())->toBe(5);

    $this->actingAs($this->admin)
        ->get(route('academic.reports.items', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/grades/report-items')
            ->where('canEdit', true)
            ->has('sheet.items', 8)
            ->has('sheet.students', 3)
            ->where("sheet.grades.{$ahmad}.{$rebana}", ['letter' => 'B', 'note' => null])
            ->where("sheet.grades.{$ahmad}.{$kerajinan}", ['letter' => 'A', 'note' => 'Rajin membantu teman']),
        );

    // Mengosongkan = dihapus.
    $this->actingAs($this->admin)->post(route('academic.reports.items.save', ['class' => $this->class->id]), [
        'semester' => $this->term->id, 'grades' => [$aisyah => [$rebana => ['letter' => '']]],
    ])->assertSessionHasNoErrors();
    expect(DB::table('ac_report_item_grades')->where('enrollment_id', $aisyah)->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => $ahmad]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.extracurriculars', [['id' => $rebana, 'name' => 'Rebana'], ['id' => $pramuka, 'name' => 'Pramuka']])
            ->has('report.personalities', 6)
            ->where("report.cards.0.extracurricular.{$rebana}", 'B')
            ->where("report.cards.0.extracurricular.{$pramuka}", null)
            ->where("report.cards.0.personality.{$kelakuan}", ['letter' => 'B', 'text' => 'Kepribadian baik, sopan, dan jujur'])
            ->where("report.cards.0.personality.{$kerajinan}", ['letter' => 'A', 'text' => 'Rajin membantu teman']),
        );

    expect(DB::table('audit_logs')->where('action', AcademicAuditAction::REPORT_ITEMS_GRADED)->exists())->toBeTrue();
});

test('peringkat kelas: dari rata-rata rapor, nilai sama tetap berurutan (jumlah lalu nama), tampil bila diaktifkan di format', function () {
    $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => 'Fiqih'])->assertSessionHasNoErrors();
    $subject = (string) DB::table('ac_subjects')->value('id');
    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), ['subject_ids' => [$subject]])->assertSessionHasNoErrors();
    $row = (string) DB::table('ac_class_subjects')->value('id');

    $grades = [];

    foreach (['Ahmad Fauzi' => '85', 'Aisyah Putri' => '85', 'Budi Santoso' => '90'] as $name => $score) {
        $grades[$this->enrollments[$name]] = ['scores' => ['PAS' => $score], 'description' => ''];
    }

    $this->actingAs($this->admin)->post(route('academic.grades.store', ['classSubject' => $row]), ['semester' => $this->term->id, 'grades' => $grades])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('academic.grades.finalize', ['classSubject' => $row]), ['semester' => $this->term->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.reports.print', ['class' => $this->class->id, 'semester' => $this->term->id, 'siswa' => $this->enrollments['Aisyah Putri']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.ranked', 3)
            ->where('report.cards.0.rank', 3)
            ->where('format.show_rank', false),
        );

    $this->actingAs($this->admin)->post(route('academic.report-formats.update', ['organization' => $this->ma]), [
        'title' => 'LAPORAN HASIL BELAJAR', 'student_term' => 'SANTRI', 'principal_title' => 'Kepala Madrasah', 'show_rank' => '1',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('academic.reports.index', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('showRank', true)
            ->where('ranked', 3)
            // Urut nama: Ahmad, Aisyah, Budi → Budi 1; Ahmad & Aisyah sama 85 → Ahmad 2, Aisyah 3.
            ->where('students.0.rank', 2)
            ->where('students.1.rank', 3)
            ->where('students.2.rank', 1),
        );

    expect(ReportCards::ranks([
        ['name' => 'B', 'left' => false, 'average' => 80.0, 'total' => 160.0],
        ['name' => 'A', 'left' => false, 'average' => 80.0, 'total' => 160.0],
        ['name' => 'C', 'left' => true, 'average' => 99.0, 'total' => 99.0],
        ['name' => 'D', 'left' => false, 'average' => null, 'total' => null],
    ]))->toBe([1 => 1, 0 => 2]);
});
