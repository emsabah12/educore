<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\Exceptions\MembershipException;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Domain\Tenancy\WorkContext;
use Tests\Support\CoreFixtures;

/*
 * Aturan membership & penugasan, filter tenant otomatis, dan seeder akun uji (F2b).
 */

// ── Membership ────────────────────────────────────────────────────────────────

test('satu orang hanya punya satu membership per yayasan; memberi ulang memakai yang lama', function () {
    $tenant = CoreFixtures::tenant();
    $user = User::factory()->create();

    $first = CoreFixtures::member($user, $tenant);
    $first->status = MembershipStatus::Inactive;
    $first->save();

    $second = CoreFixtures::member($user, $tenant);

    expect($second->id)->toBe($first->id)
        ->and($second->isActive())->toBeTrue()
        ->and(Membership::query()->count())->toBe(1);
});

// ── Penugasan ─────────────────────────────────────────────────────────────────

test('penugasan ke lembaga milik yayasan lain ditolak', function () {
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    $unitInB = CoreFixtures::unit($tenantB, 'UNIT-B');
    $membership = CoreFixtures::member(User::factory()->create(), $tenantA);

    expect(fn () => CoreFixtures::assign($membership, $unitInB))
        ->toThrow(MembershipException::class, 'Lembaga/unit tidak ditemukan di yayasan ini.');
});

test('database menolak penugasan lintas yayasan walaupun service dilewati', function () {
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    $unitInB = CoreFixtures::unit($tenantB, 'UNIT-B');
    $membership = CoreFixtures::member(User::factory()->create(), $tenantA);

    expect(fn () => DB::table('organizational_assignments')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantA->id,
        'membership_id' => $membership->id,
        'organization_id' => $unitInB->id,
        'status' => 'ACTIVE',
    ]))->toThrow(QueryException::class);
});

test('penugasan tingkat Yayasan wajib memakai filter jenjang', function () {
    $tenant = CoreFixtures::tenant();
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);

    expect(fn () => CoreFixtures::assign($membership, null))
        ->toThrow(MembershipException::class, 'Penugasan di tingkat Yayasan hanya untuk penugasan fungsional (wajib memilih jenjang).');
});

test('penugasan yang sama tidak boleh dobel, termasuk penugasan fungsional tingkat Yayasan', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'UNIT-1');
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);

    CoreFixtures::assign($membership, $unit);
    CoreFixtures::assign($membership, null, Jenjang::Mda);

    expect(fn () => CoreFixtures::assign($membership, $unit))
        ->toThrow(MembershipException::class, 'Penugasan yang sama sudah ada.');
    expect(fn () => CoreFixtures::assign($membership, null, Jenjang::Mda))
        ->toThrow(MembershipException::class, 'Penugasan yang sama sudah ada.');
});

test('database menolak penugasan fungsional tingkat Yayasan yang dobel', function () {
    $tenant = CoreFixtures::tenant();
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);
    CoreFixtures::assign($membership, null, Jenjang::Mda);

    expect(fn () => DB::table('organizational_assignments')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'membership_id' => $membership->id,
        'organization_id' => null,
        'jenjang_filter' => 'MDA',
        'status' => 'ACTIVE',
    ]))->toThrow(QueryException::class);
});

test('penugasan ke lembaga nonaktif atau dengan membership nonaktif ditolak', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'UNIT-1');
    $unit->status = OrganizationStatus::Inactive;
    $unit->save();
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);

    expect(fn () => CoreFixtures::assign($membership, $unit))
        ->toThrow(MembershipException::class, 'Lembaga/unit sudah nonaktif.');

    $membership->status = MembershipStatus::Inactive;
    $membership->save();

    expect(fn () => CoreFixtures::assign($membership, null, Jenjang::Mda))
        ->toThrow(MembershipException::class, 'Keanggotaan di yayasan ini sudah nonaktif.');
});

// ── Filter tenant otomatis (BelongsToTenant) ──────────────────────────────────

test('dengan konteks kerja aktif, data lembaga yayasan lain otomatis tersaring', function () {
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    CoreFixtures::unit($tenantA, 'UNIT-A');
    $unitB = CoreFixtures::unit($tenantB, 'UNIT-B');

    // Tanpa konteks (mis. konsol): tidak ada filter otomatis.
    expect(Organization::query()->count())->toBe(2);

    app(TenantContext::class)->set(new WorkContext(
        tenantId: $tenantA->id,
        tenantName: $tenantA->name,
        membershipId: (string) Str::uuid7(),
        assignmentId: null,
        organizationId: null,
        organizationName: null,
        jenjangFilter: null,
        canSwitchTenant: false,
        canSwitchWorkspace: false,
    ));

    expect(Organization::query()->pluck('code')->all())->toBe(['UNIT-A'])
        ->and(Organization::query()->find($unitB->id))->toBeNull()
        ->and(OrganizationalAssignment::query()->count())->toBe(0)
        ->and(Organization::query()->withoutGlobalScope('tenant')->count())->toBe(2);

    app(TenantContext::class)->clear();
});

// ── Seeder akun uji (OD-10) ───────────────────────────────────────────────────

test('seeder akun uji membuat akun per peran sesuai skenario', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $byUsername = fn (string $username): User => User::query()->where('username', $username)->firstOrFail();

    expect($byUsername('superadmin')->is_superadmin)->toBeTrue();

    $countAssignments = fn (string $username): int => OrganizationalAssignment::query()
        ->whereHas('membership', fn ($membership) => $membership->where('person_id', $byUsername($username)->person_id))
        ->count();

    expect($countAssignments('admin.yayasan'))->toBe(0)
        ->and($countAssignments('kepala.unit1'))->toBe(1)
        ->and($countAssignments('guru'))->toBe(2)
        ->and($countAssignments('koordinator.mda'))->toBe(2)
        ->and(Membership::query()->where('person_id', $byUsername('penguji')->person_id)->exists())->toBeFalse();

    $functional = OrganizationalAssignment::query()->whereNotNull('jenjang_filter')->sole();
    expect($functional->organization_id)->toBeNull()
        ->and($functional->jenjang_filter)->toBe(Jenjang::Mda);
});

test('seeder akun uji aman dijalankan dua kali', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    expect(User::query()->count())->toBe(6)
        ->and(Membership::query()->count())->toBe(4)
        ->and(OrganizationalAssignment::query()->count())->toBe(5);
});

test('seeder akun uji menolak berjalan di production', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->app['env'] = 'production';

    expect(fn () => app(DevAccountsSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'DevAccountsSeeder hanya boleh dijalankan di lingkungan local/testing.');

    expect(User::query()->count())->toBe(0);

    $this->app['env'] = 'testing';
});

test('akun uji bisa login dan langsung mendapat konteks sesuai perannya', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->post(route('login.store'), ['login' => 'kepala.unit1', 'password' => DevAccountsSeeder::PASSWORD]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('context.workspace.label', 'Unit 1'));
});
