<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicDevSeeder;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Domain\Fee\FeeType;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Pengaturan keuangan: jenis biaya & tarif (PRD-003 §4–§6, FN-1a). "Hari ini" 28 Okt 2026; kalender
 * bawaan punya tahun ajaran 2026/2027 (berjalan). Kalender lain "Kalender MDA" (2026/2027) tidak dipakai
 * SMP Unit 3.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 09:00:00', BusinessClock::timezone()));

    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicDevSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->smp = CoreFixtures::node($this->tenant, 'U3-SMP');
    $this->year = AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_current', true)->sole();

    $this->actingAs($this->admin)->post(route('academic.calendar.store'), ['name' => 'Kalender MDA'])->assertSessionHasNoErrors();
    $mda = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('name', 'Kalender MDA')->sole();
    $this->post(route('academic.years.store', ['calendar' => $mda->id]), [
        'name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
        'terms' => [['name' => 'Semester Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31'], ['name' => 'Semester Genap', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30']],
    ])->assertSessionHasNoErrors();
    $this->otherYear = AcademicYear::query()->withoutGlobalScope('tenant')->where('calendar_id', $mda->id)->sole();
});

function fnsType(string $code): FeeType
{
    return FeeType::query()->withoutGlobalScope('tenant')->where('code', $code)->sole();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnsRate(mixed $test, array $overrides = []): array
{
    return array_merge(['organization_id' => $test->smp->id, 'academic_year_id' => $test->year->id, 'amount' => '150.000'], $overrides);
}

test('jenis biaya: tambah, kode unik & huruf besar, sifat tetap, ubah nama, nonaktif/aktif; audit', function () {
    $this->post(route('finance.fee-types.store'), ['code' => ' spp ', 'name' => '  SPP   bulanan ', 'kind' => 'BULANAN'])->assertSessionHasNoErrors();
    $this->post(route('finance.fee-types.store'), ['code' => 'DAFTAR-ULANG', 'name' => 'Daftar ulang', 'kind' => 'SEKALI'])->assertSessionHasNoErrors();

    $spp = fnsType('SPP');
    expect($spp->name)->toBe('SPP bulanan')->and($spp->kind->value)->toBe('BULANAN');

    $this->post(route('finance.fee-types.store'), ['code' => 'SPP', 'name' => 'Lagi', 'kind' => 'SEKALI'])
        ->assertSessionHasErrors(['code' => 'Kode SPP sudah dipakai jenis biaya lain.']);
    $this->post(route('finance.fee-types.store'), ['code' => 'X', 'name' => 'Lain', 'kind' => 'SEKALI'])
        ->assertSessionHasErrors(['code' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.']);
    $this->post(route('finance.fee-types.store'), ['code' => 'LAIN', 'name' => '', 'kind' => 'LAIN'])
        ->assertSessionHasErrors(['name', 'kind']);

    $this->patch(route('finance.fee-types.update', ['type' => $spp->id]), ['name' => 'SPP', 'kind' => 'SEKALI'])->assertSessionHasNoErrors();
    expect($spp->refresh()->name)->toBe('SPP')->and($spp->kind->value)->toBe('BULANAN');

    $this->post(route('finance.fee-types.deactivate', ['type' => $spp->id]))->assertSessionHasNoErrors();
    $this->post(route('finance.fee-types.deactivate', ['type' => $spp->id]))->assertSessionHasErrors(['form' => 'SPP sudah nonaktif.']);
    $this->post(route('finance.fee-types.activate', ['type' => $spp->id]))->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('action', 'like', 'finance.fee_type_%')->pluck('action')->all())->toEqualCanonicalizing([
        FinanceAuditAction::FEE_TYPE_CREATED, FinanceAuditAction::FEE_TYPE_CREATED, FinanceAuditAction::FEE_TYPE_UPDATED,
        FinanceAuditAction::FEE_TYPE_DEACTIVATED, FinanceAuditAction::FEE_TYPE_ACTIVATED,
    ]);
});

test('tarif: semua tingkat & per tingkat, simpan ulang = ubah nominal, hapus; tampil di halaman per lembaga × tahun', function () {
    $this->post(route('finance.fee-types.store'), ['code' => 'SPP', 'name' => 'SPP', 'kind' => 'BULANAN'])->assertSessionHasNoErrors();
    $spp = fnsType('SPP');
    $url = route('finance.fee-rates.set', ['type' => $spp->id]);

    $this->post($url, fnsRate($this))->assertSessionHasNoErrors();
    $this->post($url, fnsRate($this, ['grade' => 9, 'amount' => 'Rp 175.000']))->assertSessionHasNoErrors();
    $this->post($url, fnsRate($this, ['amount' => '160000']))->assertSessionHasNoErrors();

    expect(DB::table('fn_fee_rates')->count())->toBe(2)
        ->and(DB::table('fn_fee_rates')->whereNull('grade')->value('amount'))->toBe(160000)
        ->and(DB::table('fn_fee_rates')->where('grade', 9)->value('amount'))->toBe(175000);

    $this->get(route('finance.settings.index', ['lembaga' => $this->smp->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/settings')
            ->where('organizationId', $this->smp->id)
            ->where('yearId', $this->year->id)
            ->where('years.0.label', '2026/2027 (berjalan)')
            ->where('types.0.rates', 2)
            ->where('rates.0.all.amount', 160000)
            ->where('rates.0.grades.0.grade', 9)
            ->where('rates.0.grades.0.amount', 175000));

    $rate = (string) DB::table('fn_fee_rates')->where('grade', 9)->value('id');
    $this->delete(route('finance.fee-rates.remove', ['rate' => $rate]))->assertSessionHasNoErrors();
    expect(DB::table('fn_fee_rates')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::FEE_RATE_SET)->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('action', FinanceAuditAction::FEE_RATE_REMOVED)->count())->toBe(1);
});

test('tarif ditolak: nominal salah, tingkat di luar 1–12, tahun ajaran bukan kalender lembaga, bukan lembaga, jenis nonaktif', function () {
    $this->post(route('finance.fee-types.store'), ['code' => 'SPP', 'name' => 'SPP', 'kind' => 'BULANAN'])->assertSessionHasNoErrors();
    $spp = fnsType('SPP');
    $url = route('finance.fee-rates.set', ['type' => $spp->id]);

    $this->post($url, fnsRate($this, ['amount' => '0']))->assertSessionHasErrors(['amount' => 'Nominal tarif harus rupiah bulat Rp 1 s.d. Rp 100.000.000 (tanpa koma).']);
    $this->post($url, fnsRate($this, ['amount' => '150.000,50']))->assertSessionHasErrors('amount');
    $this->post($url, fnsRate($this, ['amount' => '100000001']))->assertSessionHasErrors('amount');
    $this->post($url, fnsRate($this, ['grade' => 13]))->assertSessionHasErrors('grade');
    $this->post($url, fnsRate($this, ['academic_year_id' => $this->otherYear->id]))
        ->assertSessionHasErrors(['academic_year_id' => 'Pilih tahun ajaran dari kalender akademik SMP Unit 3.']);
    $this->post($url, fnsRate($this, ['organization_id' => CoreFixtures::node($this->tenant, 'PONPES-U1')->id]))->assertSessionHasErrors('organization_id');
    $this->post($url, fnsRate($this, ['organization_id' => (string) Str::uuid7()]))->assertSessionHasErrors('organization_id');

    $this->post(route('finance.fee-types.deactivate', ['type' => $spp->id]))->assertSessionHasNoErrors();
    $this->post($url, fnsRate($this))->assertSessionHasErrors(['form' => 'SPP nonaktif. Aktifkan dulu untuk memberi tarif.']);

    expect(DB::table('fn_fee_rates')->count())->toBe(0);
});

test('hak akses: Admin Keuangan boleh; Bendahara & Kepala Lembaga 403', function () {
    CoreFixtures::syncAccess();

    $adminKeuangan = User::factory()->create();
    CoreFixtures::tenantRole(CoreFixtures::member($adminKeuangan, $this->tenant), FinanceAccess::ROLE_ADMIN_KEUANGAN);

    $bendahara = User::factory()->create();
    CoreFixtures::assignmentRole(CoreFixtures::assign(CoreFixtures::member($bendahara, $this->tenant), $this->smp), FinanceAccess::ROLE_BENDAHARA);

    $this->flushSession();
    $this->actingAs($adminKeuangan)->get(route('finance.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => collect($permissions)->contains(FinanceAccess::SETTINGS_MANAGE)));
    $this->actingAs($adminKeuangan)->post(route('finance.fee-types.store'), ['code' => 'SPP', 'name' => 'SPP', 'kind' => 'BULANAN'])->assertSessionHasNoErrors();

    $this->flushSession();
    $this->actingAs($bendahara)->get(route('finance.settings.index'))->assertForbidden();
    $this->actingAs($bendahara)->post(route('finance.fee-types.store'), ['code' => 'LAIN', 'name' => 'Lain', 'kind' => 'SEKALI'])->assertForbidden();

    $this->flushSession();
    $this->actingAs(DevAccounts::user('kepala.smk'))->get(route('finance.settings.index'))->assertForbidden();

    expect(FeeType::query()->withoutGlobalScope('tenant')->count())->toBe(1);
});
