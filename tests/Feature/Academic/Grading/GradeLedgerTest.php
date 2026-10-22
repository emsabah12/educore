<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Term;
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
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Leger nilai kelas (PRD-002 §19 AC-5c).
 *
 * "Hari ini" 22 Okt 2026, Semester Ganjil 2026/2027. MA Unit 1, kelas X-A (wali kelas: akun `guru`)
 * berisi Ahmad, Aisyah, Budi. Aturan bawaan (KKM 75, 40/30/30).
 *   Akidah (DRAF):  Ahmad 90, Aisyah 70
 *   Fiqih (FINAL, kode FQH): Ahmad 80, Aisyah 66, Budi 93
 *   Tarikh: belum ada lembar
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();
    $this->term = Term::query()->withoutGlobalScope('tenant')->where('academic_year_id', $this->year->id)->where('sequence', 1)->sole();

    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole();
    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id, membershipId: $membership->id, name: 'Guru MA', gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: $this->ma, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ));

    foreach ([['X-A', $employee->id], ['X-B', null]] as [$name, $homeroom]) {
        $this->actingAs($this->admin)->post(route('academic.classes.store'), [
            'organization_id' => $this->ma, 'academic_year_id' => $this->year->id, 'grade' => 10, 'name' => $name,
            'homeroom_employee_id' => $homeroom ?? '',
        ])->assertSessionHasNoErrors();
    }

    $this->class = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-A')->sole();

    foreach (['Ahmad Fauzi', 'Aisyah Putri', 'Budi Santoso'] as $name) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $this->ma, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'class_id' => $this->class->id,
        ])->assertSessionHasNoErrors();
    }

    foreach ([['Akidah', ''], ['Fiqih', 'FQH'], ['Tarikh', '']] as [$name, $code]) {
        $this->actingAs($this->admin)->post(route('academic.subjects.store'), ['organization_id' => $this->ma, 'name' => $name, 'code' => $code])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)->post(route('academic.class-subjects.store', ['class' => $this->class->id]), [
        'subject_ids' => DB::table('ac_subjects')->where('organization_id', $this->ma)->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $row = fn (string $subject): string => (string) DB::table('ac_class_subjects as cs')->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')->where('s.name', $subject)->value('cs.id');
    $this->subjects = ['Akidah' => $row('Akidah'), 'Fiqih' => $row('Fiqih'), 'Tarikh' => $row('Tarikh')];
    $enrollment = fn (string $name): string => (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');

    $save = function (string $subject, array $scores): void {
        $grades = [];

        foreach ($scores as $id => $value) {
            $grades[$id] = ['scores' => ['HARIAN' => $value[0], 'PTS' => $value[1], 'PAS' => $value[2]]];
        }

        $this->actingAs($this->admin)
            ->post(route('academic.grades.store', ['classSubject' => $this->subjects[$subject]]), ['semester' => $this->term->id, 'grades' => $grades])
            ->assertSessionHasNoErrors();
    };

    $save('Akidah', [$enrollment('Ahmad Fauzi') => ['90', '90', '90'], $enrollment('Aisyah Putri') => ['70', '70', '70']]);
    $save('Fiqih', [
        $enrollment('Ahmad Fauzi') => ['80', '80', '80'],
        $enrollment('Aisyah Putri') => ['60', '70', '70'],
        $enrollment('Budi Santoso') => ['95', '90', '92'],
    ]);

    $this->actingAs($this->admin)
        ->post(route('academic.grades.finalize', ['classSubject' => $this->subjects['Fiqih']]), ['semester' => $this->term->id])
        ->assertSessionHasNoErrors();
});

/**
 * @return list<list<mixed>>
 */
function lgrRows(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
        }

        break;
    }

    $reader->close();
    @unlink($path);

    return $rows;
}

test('leger: nilai akhir per mapel, rata-rata siswa/mapel/kelas, jumlah di bawah KKM, status lembar', function () {
    $this->actingAs($this->admin)
        ->get(route('academic.ledger.show', ['class' => $this->class->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academic/grades/ledger')
            ->where('term.value', $this->term->id)
            ->where('sheetBase', '/kelas/mapel')
            ->where('ledger.final_count', 1)
            ->where('ledger.subjects.0', ['id' => $this->subjects['Akidah'], 'name' => 'Akidah', 'code' => null, 'status' => 'DRAFT', 'kkm' => 75])
            ->where('ledger.subjects.1.status', 'FINAL')
            ->where('ledger.subjects.1.code', 'FQH')
            ->where('ledger.subjects.2.status', 'BELUM')
            ->where('ledger.students.0.name', 'Ahmad Fauzi')
            ->where('ledger.students.0.grades', [$this->subjects['Akidah'] => 90, $this->subjects['Fiqih'] => 80, $this->subjects['Tarikh'] => null])
            ->where('ledger.students.0.average', 85)
            ->where('ledger.students.0.below', 0)
            ->where('ledger.students.1.average', 68)
            ->where('ledger.students.1.below', 2)
            ->where('ledger.students.2.grades.'.$this->subjects['Akidah'], null)
            ->where('ledger.students.2.average', 93)
            ->where('ledger.subject_averages', [$this->subjects['Akidah'] => 80, $this->subjects['Fiqih'] => 79.7, $this->subjects['Tarikh'] => null])
            ->where('ledger.class_average', 79.8),
        );
});

test('unduh leger .xlsx: judul mapel bertanda draf/belum ada, baris KKM, baris rata-rata mapel', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('academic.ledger.export', ['class' => $this->class->id, 'semester' => $this->term->id]))
        ->assertOk()
        ->assertDownload('leger-x-a-semester-ganjil-'.Str::slug($this->year->name).'.xlsx');

    $rows = lgrRows($response);

    expect($rows[0])->toBe(['No', 'Nama', 'NISN', 'Nomor induk', 'Akidah (draf)', 'Fiqih', 'Tarikh (belum ada)', 'Rata-rata', 'Di bawah KKM'])
        ->and([$rows[1][1], (int) $rows[1][4], (int) $rows[1][5]])->toBe(['KKM', 75, 75])
        ->and([$rows[2][1], (int) $rows[2][4], (int) $rows[2][5], $rows[2][6] ?? '', (float) $rows[2][7]])->toBe(['Ahmad Fauzi', 90, 80, '', 85.0])
        ->and([$rows[3][1], (int) $rows[3][8]])->toBe(['Aisyah Putri', 2])
        ->and($rows[5][1])->toBe('Rata-rata mapel')
        ->and((float) $rows[5][7])->toBe(79.8);
});

test('wali kelas membuka leger dari Kelas saya (tanpa tautan lembar nilai); kelas lain 404; pintu biasa 403; di luar cakupan 404', function () {
    $this->flushSession();
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');

    $this->actingAs($this->guru)
        ->get(route('academic.my-ledger.show', ['class' => $this->class->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sheetBase', null)->where('ledger.final_count', 1)->where('backUrl', "/kelas-saya/{$this->class->id}"));
    $this->actingAs($this->guru)->get(route('academic.my-ledger.export', ['class' => $this->class->id]))->assertOk();

    $other = SchoolClass::query()->withoutGlobalScope('tenant')->where('name', 'X-B')->sole();
    $this->actingAs($this->guru)->get(route('academic.my-ledger.show', ['class' => $other->id]))->assertNotFound();
    $this->actingAs($this->guru)->get(route('academic.ledger.show', ['class' => $this->class->id]))->assertForbidden();

    $this->flushSession();
    $this->actingAs(DevAccounts::user(AcademicDevSeeder::OPERATOR_USERNAME))->get(route('academic.ledger.show', ['class' => $this->class->id]))->assertNotFound();
});
