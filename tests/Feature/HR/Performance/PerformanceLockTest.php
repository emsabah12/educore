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
 * Penilaian kinerja HR-8b-2 (PRD-001 §19.2–§19.3): kunci/buka kunci periode, Kinerja saya, rekap & unduhan .xlsx.
 * "Sekarang" Jumat 13 Nov 2026. Guru (akun `guru`) & Budi di MA Unit 1, Rina di SMK Unit 3.
 */

function pkkEmployee(mixed $test, string $name, string $orgCode, ?string $membershipId = null): string
{
    $catalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $test->tenant->id)->where('code', $code)->value('id');

    return app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $test->tenant->id, membershipId: $membershipId, name: $name, gender: Gender::Female,
        birthPlace: null, birthDate: null, phone: null, address: null, employeeNumber: null, nuptk: null,
        educationLevel: null, educationMajor: null, employmentTypeId: $catalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null, startDate: '2020-07-01', organizationId: CoreFixtures::node($test->tenant, $orgCode)->id, positionId: $catalog(CatalogKind::Position, 'GURU'),
    ))->id;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->guru = DevAccounts::user('guru');
    $this->guruId = pkkEmployee($this, 'Guru', 'U1-MA', Membership::query()->where('tenant_id', $this->tenant->id)->where('person_id', $this->guru->person_id)->sole()->id);
    $this->budi = pkkEmployee($this, 'Budi', 'U1-MA');
    $this->rina = pkkEmployee($this, 'Rina', 'U3-SMK');

    $this->actingAs($this->admin)->post(route('hr.performance.periods.store'), ['name' => 'Semester Ganjil 2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19']);
    $this->period = (string) DB::table('hr_performance_periods')->value('id');
    $this->post(route('hr.performance.assess', ['period' => $this->period, 'employee' => $this->guruId]), ['group' => 'STAF', 'scores' => [95, 90, 92, 91], 'strengths' => 'Disiplin']);
    $this->post(route('hr.performance.assess', ['period' => $this->period, 'employee' => $this->rina]), ['group' => 'STAF', 'scores' => [70, 70, 70, 70]]);
    $this->flushSession();
});

test('kunci periode: nilai & periode tidak bisa diubah; pegawai melihat hasilnya; buka kunci wajib alasan lalu tersembunyi lagi', function () {
    // Sebelum dikunci, pegawai belum melihat hasilnya.
    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-performance.index'))
        ->assertInertia(fn (Assert $page) => $page->component('hr/my-performance')->where('linked', true)->has('results', 0));
    $this->flushSession();

    $this->actingAs($this->admin)->post(route('hr.performance.periods.lock', ['period' => $this->period]))->assertSessionHasNoErrors();
    expect(DB::table('hr_performance_periods')->value('locked_at'))->not->toBeNull();

    $this->post(route('hr.performance.assess', ['period' => $this->period, 'employee' => $this->budi]), ['group' => 'STAF', 'scores' => [80, 80, 80, 80]])
        ->assertSessionHasErrors(['form' => 'Periode ini sudah dikunci; nilai tidak bisa diubah.']);
    $this->patch(route('hr.performance.periods.update', ['period' => $this->period]), ['name' => 'Ganti', 'start_date' => '2026-07-13', 'end_date' => '2026-12-19'])
        ->assertSessionHasErrors(['form' => 'Periode ini sudah dikunci.']);
    $this->post(route('hr.performance.periods.lock', ['period' => $this->period]))->assertSessionHasErrors(['form' => 'Periode ini sudah dikunci.']);
    $this->flushSession();

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-performance.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results', 1)
            ->where('results.0.period', 'Semester Ganjil 2026/2027')
            ->where('results.0.assessment.final_score', 92)
            ->where('results.0.assessment.predicate_label', 'Amat baik')
            ->where('results.0.assessment.strengths', 'Disiplin')
            ->where('results.0.assessment.assessed_by', 'Admin Yayasan'));
    $this->flushSession();

    $this->actingAs($this->admin)->post(route('hr.performance.periods.unlock', ['period' => $this->period]), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('hr.performance.periods.unlock', ['period' => $this->period]), ['reason' => 'Koreksi nilai Budi'])->assertSessionHasNoErrors();
    expect(DB::table('hr_performance_periods')->value('locked_at'))->toBeNull()
        ->and((string) DB::table('audit_logs')->where('action', HrAuditAction::PERFORMANCE_PERIOD_UNLOCKED)->value('changes'))->toContain('Koreksi nilai Budi');
    $this->post(route('hr.performance.assess', ['period' => $this->period, 'employee' => $this->budi]), ['group' => 'STAF', 'scores' => [80, 80, 80, 80]])->assertSessionHasNoErrors();
    $this->flushSession();

    DevAccounts::chooseWorkspace($this, $this->guru, 'U1-MA');
    $this->actingAs($this->guru)->get(route('hr.my-performance.index'))->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

test('rekap per lembaga & unduh .xlsx; periode belum dimulai tidak bisa dikunci; Kepala Lembaga tidak bisa mengunci/mengunduh', function () {
    $this->actingAs($this->admin)->get(route('hr.performance.index', ['periode' => $this->period]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recap', fn ($recap) => ($ma = collect($recap)->firstWhere('organization', 'MA Unit 1')) !== null
                && $ma['active'] === 2 && $ma['assessed'] === 1 && $ma['average'] === 92 && $ma['predicates']['AMAT_BAIK'] === 1
                && collect($recap)->firstWhere('organization', 'SMK Unit 3')['predicates']['CUKUP'] === 1));
    $this->get(route('hr.performance.export', ['periode' => $this->period]))->assertOk()->assertDownload('rekap-kinerja-semester-ganjil-2026-2027.xlsx');
    $this->get(route('hr.performance.export', ['periode' => '01960000-0000-7000-8000-000000000000']))->assertNotFound();

    $this->post(route('hr.performance.periods.store'), ['name' => 'Semester Genap 2026/2027', 'start_date' => '2027-01-04', 'end_date' => '2027-06-19']);
    $future = (string) DB::table('hr_performance_periods')->where('name', 'Semester Genap 2026/2027')->value('id');
    $this->post(route('hr.performance.periods.lock', ['period' => $future]))
        ->assertSessionHasErrors(['form' => 'Periode ini belum dimulai, jadi belum bisa dikunci.']);
    $this->flushSession();

    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('hr.performance.index'))->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('recap', []));
    $this->actingAs($kepala)->post(route('hr.performance.periods.lock', ['period' => $this->period]))->assertForbidden();
    $this->actingAs($kepala)->get(route('hr.performance.export', ['periode' => $this->period]))->assertForbidden();
});
