<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Domain\Fee\FeeKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Potongan/beasiswa siswa (PRD-003 §4, FN-1b). "Hari ini" 29 Okt 2026, tahun ajaran 2026/2027 berjalan.
 *
 * SMP Unit 3: Ahmad Fauzi (NIS SMP-001), Citra Lestari. MTs Unit 1: Budi Santoso.
 * Jenis biaya: SPP (bulanan), Seragam (sekali bayar, dinonaktifkan di test tertentu).
 * Bendahara SMP: akun baru dengan penugasan di SMP Unit 3.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->mts = CoreFixtures::node($this->tenant, 'U1-MTS');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    foreach ([['Ahmad Fauzi', $this->smp, 'SMP-001'], ['Citra Lestari', $this->smp, null], ['Budi Santoso', $this->mts, null]] as [$name, $organization, $number]) {
        $this->actingAs($this->admin)->post(route('academic.students.store'), [
            'name' => $name, 'gender' => 'L', 'organization_id' => $organization->id, 'start_date' => '2026-07-13', 'entry_type' => 'BARU',
            'student_number' => $number,
        ])->assertSessionHasNoErrors();
    }

    $this->spp = app(ManageFeeTypes::class)->create($this->tenant->id, 'SPP', 'SPP', FeeKind::Monthly);
    $this->seragam = app(ManageFeeTypes::class)->create($this->tenant->id, 'SERAGAM', 'Seragam', FeeKind::Once);

    CoreFixtures::syncAccess();
    $this->bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($this->bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
});

function fndEnrollment(string $name): string
{
    return (string) DB::table('ac_enrollments as e')
        ->join('ac_students as s', 's.id', '=', 'e.student_id')
        ->join('memberships as m', 'm.id', '=', 's.membership_id')
        ->join('persons as p', 'p.id', '=', 'm.person_id')
        ->where('p.name', $name)
        ->value('e.id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fndInput(mixed $test, string $student, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $test->smp->id,
        'academic_year_id' => $test->year->id,
        'enrollment_id' => fndEnrollment($student),
        'fee_type_id' => $test->spp->id,
        'kind' => 'PERSEN',
        'value' => '50',
        'reason' => 'Yatim',
    ], $overrides);
}

test('Bendahara: cari siswa aktif lembaganya, tambah potongan persen, simpan ulang = ubah ke nominal; audit tanpa alasan', function () {
    $this->actingAs($this->bendahara);

    $this->getJson(route('finance.students.search', ['lembaga' => $this->smp->id, 'q' => 'smp-0']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Ahmad Fauzi')
        ->assertJsonPath('results.0.student_number', 'SMP-001')
        ->assertJsonCount(1, 'results');
    $this->getJson(route('finance.students.search', ['lembaga' => $this->smp->id, 'q' => 'budi']))->assertJsonPath('results', []);
    $this->getJson(route('finance.students.search', ['lembaga' => $this->smp->id, 'q' => 'a']))->assertStatus(422);

    $this->post(route('finance.discounts.store'), fndInput($this, 'Ahmad Fauzi'))->assertSessionHasNoErrors();
    $this->post(route('finance.discounts.store'), fndInput($this, 'Ahmad Fauzi', ['kind' => 'NOMINAL', 'value' => 'Rp 50.000', 'reason' => 'Anak guru']))->assertSessionHasNoErrors();

    expect(DB::table('fn_discounts')->count())->toBe(1)
        ->and(DB::table('fn_discounts')->value('percent'))->toBeNull()
        ->and(DB::table('fn_discounts')->value('amount'))->toBe(50000);

    $this->get(route('finance.discounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/discounts')
            ->where('organizations', [['value' => $this->smp->id, 'label' => 'SMP Unit 3']])
            ->where('yearId', $this->year->id)
            ->where('canManage', true)
            ->has('discounts', 1)
            ->where('discounts.0.name', 'Ahmad Fauzi')
            ->where('discounts.0.fee_type', 'SPP')
            ->where('discounts.0.label', 'Rp 50.000')
            ->where('discounts.0.reason', 'Anak guru'));

    $audit = DB::table('audit_logs')->where('action', FinanceAuditAction::DISCOUNT_SET)->pluck('changes')->implode(' ');
    expect(DB::table('audit_logs')->where('action', FinanceAuditAction::DISCOUNT_SET)->count())->toBe(2)
        ->and($audit)->not->toContain('Yatim')->not->toContain('Anak guru');

    $this->delete(route('finance.discounts.destroy', ['discount' => (string) DB::table('fn_discounts')->value('id')]))->assertSessionHasNoErrors();
    expect(DB::table('fn_discounts')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::DISCOUNT_REMOVED)->count())->toBe(1);
});

test('isian ditolak: persen/nominal di luar batas, alasan kosong, siswa lembaga lain, jenis biaya nonaktif, tahun ajaran asal-asalan', function () {
    $this->actingAs($this->bendahara);
    $store = route('finance.discounts.store');

    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['value' => '0']))->assertSessionHasErrors(['value' => 'Persen potongan harus angka bulat 1–100.']);
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['value' => '101']))->assertSessionHasErrors('value');
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['value' => '12,5']))->assertSessionHasErrors('value');
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['kind' => 'NOMINAL', 'value' => '0']))->assertSessionHasErrors('value');
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['kind' => 'LAIN']))->assertSessionHasErrors('kind');
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['reason' => '   ']))->assertSessionHasErrors('reason');
    $this->post($store, fndInput($this, 'Budi Santoso'))->assertSessionHasErrors(['enrollment_id' => 'Pilih siswa yang masih terdaftar di lembaga ini.']);
    $this->post($store, fndInput($this, 'Ahmad Fauzi', ['academic_year_id' => (string) Str::uuid7()]))->assertSessionHasErrors('academic_year_id');

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('finance.fee-types.deactivate', ['type' => $this->seragam->id]))->assertSessionHasNoErrors();
    $this->flushSession();
    $this->actingAs($this->bendahara)->post($store, fndInput($this, 'Ahmad Fauzi', ['fee_type_id' => $this->seragam->id]))
        ->assertSessionHasErrors(['fee_type_id' => 'Seragam nonaktif; tidak bisa diberi potongan.']);

    expect(DB::table('fn_discounts')->count())->toBe(0);
});

test('hak akses: Kepala Lembaga hanya melihat; lembaga di luar cakupan 404; tanpa izin lihat tagihan 403', function () {
    // Admin Yayasan mencatat potongan di MTs Unit 1.
    $this->actingAs($this->admin)->post(route('finance.discounts.store'), fndInput($this, 'Budi Santoso', ['organization_id' => $this->mts->id]))->assertSessionHasNoErrors();
    $budiDiscount = (string) DB::table('fn_discounts')->value('id');
    $this->flushSession();

    // Kepala Unit 1 melihat MTs Unit 1 (di bawah unitnya) tetapi tidak boleh mengelola.
    $kepala = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepala)->get(route('finance.discounts.index', ['lembaga' => $this->mts->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('organizationId', $this->mts->id)->where('canManage', false)->has('discounts', 1));
    $this->actingAs($kepala)->post(route('finance.discounts.store'), fndInput($this, 'Budi Santoso', ['organization_id' => $this->mts->id]))->assertForbidden();
    $this->actingAs($kepala)->delete(route('finance.discounts.destroy', ['discount' => $budiDiscount]))->assertForbidden();

    // Bendahara SMP: MTs di luar cakupannya.
    $this->flushSession();
    $this->actingAs($this->bendahara)->post(route('finance.discounts.store'), fndInput($this, 'Budi Santoso', ['organization_id' => $this->mts->id]))->assertNotFound();
    $this->actingAs($this->bendahara)->delete(route('finance.discounts.destroy', ['discount' => $budiDiscount]))->assertNotFound();
    $this->actingAs($this->bendahara)->getJson(route('finance.students.search', ['lembaga' => $this->mts->id, 'q' => 'budi']))->assertNotFound();

    // Guru tanpa izin keuangan.
    $this->flushSession();
    $guru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $guru, 'U1-MA');
    $this->actingAs($guru)->get(route('finance.discounts.index'))->assertForbidden();

    expect(DB::table('fn_discounts')->count())->toBe(1);
});
