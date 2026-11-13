<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Penilaian kinerja ringkas (PRD-001 §19.2, HR-8b-1; OD-HR-17, OD-HR-18). "Sekarang" Jumat 13 Nov 2026.
 * Sari & Budi di MA Unit 1 (Kepala Unit 1 menilai), Rina di SMK Unit 3 (di luar Unit 1). Tanpa jadwal mengajar
 * semua pegawai diusulkan kelompok Staf.
 */

function pknEmployee(mixed $test, string $name, string $orgCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

/**
 * @param  list<int|string>  $scores
 * @return array<string, mixed>
 */
function pknForm(array $scores, string $group = 'STAF', array $overrides = []): array
{
    return array_merge(['group' => $group, 'scores' => $scores, 'strengths' => 'Tepat waktu', 'suggestions' => 'Ikut pelatihan TIK'], $overrides);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->kepala = DevAccounts::user('kepala.unit1');
    $this->sari = pknEmployee($this, 'Sari', 'U1-MA');
    $this->budi = pknEmployee($this, 'Budi', 'U1-MA');
    $this->rina = pknEmployee($this, 'Rina', 'U3-SMK');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $this->smk = CoreFixtures::node($this->tenant, 'U3-SMK')->id;
});

test('Admin membuat periode; aturan nama & tanggal; ubah; hapus hanya bila belum ada penilaian', function () {
    $this->actingAs($this->admin);

    $this->post(route('hr.performance.periods.store'), ['name' => 'Semester Ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19'])
        ->assertSessionHasNoErrors();
    $period = (string) DB::table('hr_performance_periods')->value('id');

    $this->post(route('hr.performance.periods.store'), ['name' => 'semester ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19'])
        ->assertSessionHasErrors(['name' => 'Nama periode ini sudah dipakai.']);
    $this->post(route('hr.performance.periods.store'), ['name' => 'Tahun 2027', 'start_date' => '2027-01-01', 'end_date' => '2026-12-31'])
        ->assertSessionHasErrors(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
    $this->post(route('hr.performance.periods.store'), ['name' => 'Dua tahun', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31'])
        ->assertSessionHasErrors('end_date');
    $this->post(route('hr.performance.periods.store'), ['name' => 'ab', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'])
        ->assertSessionHasErrors('name');

    $this->patch(route('hr.performance.periods.update', ['period' => $period]), ['name' => 'Semester Ganjil 2026', 'start_date' => '2026-07-13', 'end_date' => '2026-12-31'])
        ->assertSessionHasNoErrors();
    expect(DB::table('hr_performance_periods')->value('name'))->toBe('Semester Ganjil 2026')
        ->and(DB::table('audit_logs')->where('action', HrAuditAction::PERFORMANCE_PERIOD_UPDATED)->count())->toBe(1);

    $this->post(route('hr.performance.assess', ['period' => $period, 'employee' => $this->sari]), pknForm([80, 80, 80, 80]))->assertSessionHasNoErrors();
    $this->delete(route('hr.performance.periods.destroy', ['period' => $period]))
        ->assertSessionHasErrors(['form' => 'Periode ini sudah punya penilaian, jadi tidak bisa dihapus.']);

    $this->post(route('hr.performance.periods.store'), ['name' => 'Uji coba', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'])->assertSessionHasNoErrors();
    $trial = (string) DB::table('hr_performance_periods')->where('name', 'Uji coba')->value('id');
    $this->delete(route('hr.performance.periods.destroy', ['period' => $trial]))->assertSessionHasNoErrors();
    expect(DB::table('hr_performance_periods')->count())->toBe(1);
});

test('komponen bawaan & simpan komponen; penilaian lama tetap memakai salinan komponennya', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.performance.periods.store'), ['name' => 'Semester Ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19']);
    $period = (string) DB::table('hr_performance_periods')->value('id');

    $this->get(route('hr.performance.index'))->assertInertia(fn (Assert $page) => $page
        ->component('hr/performance/index')
        ->where('canManage', true)
        ->where('components.GURU.0', ['name' => 'Pedagogik', 'weight' => 25])
        ->has('components.STAF', 4));

    // 90×25 + 80×25 + 70×25 + 60×25 = 75,0 → Cukup (61–75).
    $this->post(route('hr.performance.assess', ['period' => $period, 'employee' => $this->sari]), pknForm([90, 80, 70, 60]))->assertSessionHasNoErrors();

    $save = route('hr.performance.components.save', ['group' => 'staf']);
    $this->post($save, ['components' => [['name' => 'Kedisiplinan', 'weight' => 50], ['name' => 'Kerja sama', 'weight' => 40]]])
        ->assertSessionHasErrors(['components' => 'Jumlah bobot harus 100%, sekarang 90%.']);
    $this->post($save, ['components' => [['name' => 'Kedisiplinan', 'weight' => 50], ['name' => 'kedisiplinan', 'weight' => 50]]])
        ->assertSessionHasErrors('components');
    $this->post($save, ['components' => [['name' => 'Kedisiplinan', 'weight' => 60], ['name' => 'Kerja sama', 'weight' => 40], ['name' => '', 'weight' => '']]])
        ->assertSessionHasNoErrors();
    expect(DB::table('audit_logs')->where('action', HrAuditAction::PERFORMANCE_COMPONENTS_UPDATED)->count())->toBe(1);

    $this->get(route('hr.performance.index', ['periode' => $period, 'lembaga' => $this->ma]))->assertInertia(fn (Assert $page) => $page
        ->has('components.STAF', 2)
        ->where('components.STAF.0', ['name' => 'Kedisiplinan', 'weight' => 60])
        ->has('rows', 2)
        ->where('rows.0.name', 'Budi')
        ->where('rows.0.assessment', null)
        ->where('rows.1.name', 'Sari')
        ->where('rows.1.suggested_group', 'STAF')
        ->has('rows.1.assessment.scores', 4)
        ->where('rows.1.assessment.final_score', 75)
        ->where('rows.1.assessment.predicate_label', 'Cukup'));

    // Penilaian baru memakai komponen baru: 90×60 + 70×40 = 82,0 → Baik.
    $this->post(route('hr.performance.assess', ['period' => $period, 'employee' => $this->budi]), pknForm([90, 70]))->assertSessionHasNoErrors();
    $budi = DB::table('hr_performance_assessments')->where('employee_id', $this->budi)->sole();
    expect((float) $budi->final_score)->toBe(82.0)->and($budi->predicate)->toBe('BAIK');
});

test('Kepala Lembaga menilai pegawai lembaganya; nilai akhir berbobot & predikat; nilai ulang menimpa; audit tanpa nilai', function () {
    $this->actingAs($this->admin)->post(route('hr.performance.periods.store'), ['name' => 'Semester Ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19']);
    $period = (string) DB::table('hr_performance_periods')->value('id');
    $this->flushSession();

    $this->actingAs($this->kepala)->get(route('hr.performance.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canManage', false)
        ->where('filters.periode', $period)
        ->where('organizations', fn ($organizations) => collect($organizations)->pluck('value')->contains($this->ma) && ! collect($organizations)->pluck('value')->contains($this->smk)));

    $assess = route('hr.performance.assess', ['period' => $period, 'employee' => $this->sari]);
    $this->post($assess, pknForm([95, 90, 92, 91]))->assertSessionHasNoErrors();
    $row = DB::table('hr_performance_assessments')->sole();
    // (95 + 90 + 92 + 91) × 25 / 100 = 92,0 → Amat baik.
    expect((float) $row->final_score)->toBe(92.0)
        ->and($row->predicate)->toBe('AMAT_BAIK')
        ->and($row->organization_id)->toBe($this->ma)
        ->and($row->strengths)->toBe('Tepat waktu');

    // Nilai ulang: (90 + 90 + 91 + 91) / 4 = 90,5 → Baik (batas Amat baik 91).
    $this->post($assess, pknForm([90, 90, 91, 91], 'STAF', ['strengths' => '']))->assertSessionHasNoErrors();
    $row = DB::table('hr_performance_assessments')->sole();
    expect((float) $row->final_score)->toBe(90.5)
        ->and($row->predicate)->toBe('BAIK')
        ->and($row->strengths)->toBeNull();

    $audit = DB::table('audit_logs')->where('action', HrAuditAction::PERFORMANCE_ASSESSED)->orderBy('created_at')->get();
    expect($audit)->toHaveCount(2)
        ->and((string) $audit[1]->changes)->not->toContain('90')
        ->and((string) $audit[1]->changes)->not->toContain('Tepat');

    // Pegawai SMK Unit 3 di luar cakupan → 404; pengelolaan → 403.
    $this->post(route('hr.performance.assess', ['period' => $period, 'employee' => $this->rina]), pknForm([80, 80, 80, 80]))->assertNotFound();
    $this->post(route('hr.performance.periods.store'), ['name' => 'Coba', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'])->assertForbidden();
    $this->post(route('hr.performance.components.save', ['group' => 'guru']), ['components' => [['name' => 'A1', 'weight' => 100]]])->assertForbidden();
});

test('aturan menilai: nilai 0–100 lengkap, periode belum dimulai, menilai diri sendiri; tanpa izin 403', function () {
    $this->actingAs($this->admin);
    $this->post(route('hr.performance.periods.store'), ['name' => 'Semester Ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19']);
    $this->post(route('hr.performance.periods.store'), ['name' => 'Semester Genap 2026/2027', 'start_date' => '2027-01-04', 'end_date' => '2027-06-19']);
    $period = (string) DB::table('hr_performance_periods')->where('name', 'Semester Ganjil 2026/2027')->value('id');
    $future = (string) DB::table('hr_performance_periods')->where('name', 'Semester Genap 2026/2027')->value('id');
    $assess = route('hr.performance.assess', ['period' => $period, 'employee' => $this->sari]);

    $this->post($assess, pknForm([101, 80, 80, 80]))->assertSessionHasErrors(['scores.0' => 'Nilai Kedisiplinan harus bilangan bulat 0–100.']);
    $this->post($assess, pknForm([80, 80, 80]))->assertSessionHasErrors('scores.3');
    $this->post($assess, pknForm([80, 80, '7.5', 80]))->assertSessionHasErrors('scores.2');
    $this->post($assess, pknForm([80, 80, 80, 80], 'X'))->assertSessionHasErrors('group');
    $this->post($assess, pknForm([80, 80, 80, 80], 'STAF', ['suggestions' => str_repeat('a', 501)]))->assertSessionHasErrors('suggestions');
    $this->post(route('hr.performance.assess', ['period' => $future, 'employee' => $this->sari]), pknForm([80, 80, 80, 80]))
        ->assertSessionHasErrors(['form' => 'Periode ini belum dimulai.']);
    expect(DB::table('hr_performance_assessments')->count())->toBe(0);

    // Bawaan periode di lembar: yang terbaru dan sudah dimulai.
    $this->get(route('hr.performance.index'))->assertInertia(fn (Assert $page) => $page->where('filters.periode', $period)->has('periods', 2));
    $this->flushSession();

    // Kepala Unit 1 juga tercatat sebagai pegawai MA Unit 1 → tidak bisa menilai dirinya sendiri.
    $membership = Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->kepala->person_id)->sole()->id;
    $self = pknEmployee($this, 'Kepala Unit 1', 'U1-MA', $membership);
    $this->actingAs($this->kepala)->get(route('hr.performance.index', ['periode' => $period, 'lembaga' => $this->ma]))
        ->assertInertia(fn (Assert $page) => $page->where('rows', fn ($rows) => collect($rows)->firstWhere('employee_id', $self)['is_self'] === true));
    $this->post(route('hr.performance.assess', ['period' => $period, 'employee' => $self]), pknForm([80, 80, 80, 80]))
        ->assertSessionHasErrors(['form' => 'Anda tidak bisa menilai kinerja diri sendiri.']);
    $this->flushSession();

    // Guru (staf) tanpa izin menilai → 403.
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('hr.performance.index'))->assertForbidden();
    $this->actingAs($guru)->post($assess, pknForm([80, 80, 80, 80]))->assertForbidden();
});
