<?php

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\TenantStatus;
use Tests\Support\CoreFixtures;

/*
 * Alur konteks kerja setelah login (PRD-000 §6, F2b, OD-08 & OD-09).
 */

// ── 0 membership ──────────────────────────────────────────────────────────────

test('user tanpa membership diarahkan ke halaman belum terdaftar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.unregistered'));

    $this->actingAs($user)
        ->get(route('context.unregistered'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('context/unregistered'));
});

test('superadmin tanpa membership diarahkan ke panel platform', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get(route('dashboard'))->assertRedirect(route('platform.home'));

    $this->actingAs($superadmin)
        ->get(route('platform.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('platform/index'));
});

test('panel platform tertutup untuk pengguna biasa', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('platform.home'))->assertForbidden();
});

// ── 1 membership ──────────────────────────────────────────────────────────────

test('satu membership tanpa penugasan langsung masuk dengan konteks Seluruh Yayasan', function () {
    $tenant = CoreFixtures::tenant('YYS-A', 'Yayasan Al-Ikhlas');
    $user = User::factory()->create();
    CoreFixtures::member($user, $tenant);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.tenant.name', 'Yayasan Al-Ikhlas')
            ->where('context.workspace.type', 'tenant')
            ->where('context.workspace.label', 'Seluruh Yayasan')
            ->where('context.can_switch_tenant', false)
            ->where('context.can_switch_workspace', false),
        );
});

test('satu penugasan langsung dipilih tanpa halaman pilihan', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'PONPES-U1', name: 'Unit 1');
    $user = User::factory()->create();
    CoreFixtures::assign(CoreFixtures::member($user, $tenant), $unit);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.type', 'organization')
            ->where('context.workspace.organization_id', $unit->id)
            ->where('context.workspace.label', 'Unit 1'),
        );
});

test('penugasan fungsional di tingkat Yayasan diberi label yang jelas', function () {
    $tenant = CoreFixtures::tenant();
    $user = User::factory()->create();
    CoreFixtures::assign(CoreFixtures::member($user, $tenant), null, Jenjang::Mda);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.type', 'functional')
            ->where('context.workspace.organization_id', null)
            ->where('context.workspace.label', 'Semua MDA (Seluruh Yayasan)'),
        );
});

// ── Lebih dari satu penugasan ─────────────────────────────────────────────────

test('dua penugasan wajib memilih lembaga kerja, lalu konteks terbentuk', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'PONPES-U1', name: 'Unit 1');
    $ma = CoreFixtures::lembaga($tenant, 'U1-MA', Jenjang::Ma, $unit, 'MA Unit 1');
    $mda = CoreFixtures::lembaga($tenant, 'U1-MDA', Jenjang::Mda, $unit, 'MDA Unit 1');
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);
    CoreFixtures::assign($membership, $ma);
    $assignmentMda = CoreFixtures::assign($membership, $mda);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.workspace.edit'));

    $this->actingAs($user)
        ->get(route('context.workspace.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('context/select-workspace')
            ->has('assignments', 2)
            ->where('assignments.0.label', 'MA Unit 1')
            ->where('assignments.0.path', 'Unit 1')
            ->where('assignments.1.label', 'MDA Unit 1'),
        );

    $this->actingAs($user)
        ->post(route('context.workspace.update'), ['assignment_id' => $assignmentMda->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.label', 'MDA Unit 1')
            ->where('context.can_switch_workspace', true),
        );
});

test('tidak bisa memilih penugasan milik orang lain', function () {
    $tenant = CoreFixtures::tenant();
    $unitA = CoreFixtures::unit($tenant, 'UNIT-A');
    $unitB = CoreFixtures::unit($tenant, 'UNIT-B');
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);
    CoreFixtures::assign($membership, $unitA);
    CoreFixtures::assign($membership, $unitB);
    $otherAssignment = CoreFixtures::assign(CoreFixtures::member(User::factory()->create(), $tenant), $unitA);

    // Bentuk membership aktif di session lebih dulu.
    $this->actingAs($user)->get(route('dashboard'));

    $this->actingAs($user)
        ->post(route('context.workspace.update'), ['assignment_id' => $otherAssignment->id])
        ->assertNotFound();
});

// ── Lebih dari satu membership ────────────────────────────────────────────────

test('dua membership wajib memilih yayasan, lalu konteks terbentuk', function () {
    $tenantA = CoreFixtures::tenant('YYS-A', 'Yayasan A');
    $tenantB = CoreFixtures::tenant('YYS-B', 'Yayasan B');
    $user = User::factory()->create();
    CoreFixtures::member($user, $tenantA);
    $membershipB = CoreFixtures::member($user, $tenantB);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.tenant.edit'));

    $this->actingAs($user)
        ->get(route('context.tenant.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('context/select-tenant')
            ->has('memberships', 2)
            ->where('memberships.0.tenant_name', 'Yayasan A')
            ->where('memberships.1.tenant_name', 'Yayasan B'),
        );

    $this->actingAs($user)
        ->post(route('context.tenant.update'), ['membership_id' => $membershipB->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.tenant.name', 'Yayasan B')
            ->where('context.can_switch_tenant', true),
        );
});

test('memilih membership orang lain atau ID asal-asalan dijawab 404', function (string $kind) {
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    $user = User::factory()->create();
    CoreFixtures::member($user, $tenantA);
    CoreFixtures::member($user, $tenantB);
    $otherMembership = CoreFixtures::member(User::factory()->create(), $tenantA);

    $membershipId = match ($kind) {
        'milik orang lain' => $otherMembership->id,
        'uuid acak' => (string) Str::uuid7(),
        'bukan uuid' => 'abc',
    };

    $this->actingAs($user)
        ->post(route('context.tenant.update'), ['membership_id' => $membershipId])
        ->assertNotFound();
})->with(['milik orang lain', 'uuid acak', 'bukan uuid']);

test('membership nonaktif dan yayasan yang disuspend tidak ikut ditawarkan', function () {
    $active = CoreFixtures::tenant('YYS-A', 'Yayasan Aktif');
    $suspended = CoreFixtures::tenant('YYS-B', 'Yayasan Disuspend');
    $inactiveMembershipTenant = CoreFixtures::tenant('YYS-C', 'Yayasan Keanggotaan Nonaktif');
    $user = User::factory()->create();

    CoreFixtures::member($user, $active);
    CoreFixtures::member($user, $suspended);
    $inactive = CoreFixtures::member($user, $inactiveMembershipTenant);

    $suspended->status = TenantStatus::Suspended;
    $suspended->save();
    $inactive->status = MembershipStatus::Inactive;
    $inactive->save();

    // Tinggal satu yang sah → langsung masuk, tanpa halaman pilih yayasan.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('context.tenant.name', 'Yayasan Aktif'));
});

// ── Validasi ulang setiap request ─────────────────────────────────────────────

test('membership yang dicabut saat sesi berjalan langsung berlaku di request berikutnya', function () {
    $tenant = CoreFixtures::tenant();
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $membership->status = MembershipStatus::Inactive;
    $membership->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.unregistered'));
});

test('penugasan yang dinonaktifkan saat sesi berjalan langsung berlaku di request berikutnya', function () {
    $tenant = CoreFixtures::tenant();
    $unitA = CoreFixtures::unit($tenant, 'UNIT-A', name: 'Unit A');
    $unitB = CoreFixtures::unit($tenant, 'UNIT-B', name: 'Unit B');
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);
    $assignmentA = CoreFixtures::assign($membership, $unitA);
    CoreFixtures::assign($membership, $unitB);

    // Request pertama membentuk yayasan aktif di session, lalu lembaga kerja dipilih.
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.workspace.edit'));
    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => $assignmentA->id]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $assignmentA->status = AssignmentStatus::Inactive;
    $assignmentA->save();

    // Tinggal satu penugasan → otomatis pindah ke Unit B (OD-08).
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('context.workspace.label', 'Unit B'));
});

test('penugasan di lembaga yang dinonaktifkan tidak lagi ditawarkan', function () {
    $tenant = CoreFixtures::tenant();
    $unitA = CoreFixtures::unit($tenant, 'UNIT-A', name: 'Unit A');
    $unitB = CoreFixtures::unit($tenant, 'UNIT-B', name: 'Unit B');
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);
    CoreFixtures::assign($membership, $unitA);
    CoreFixtures::assign($membership, $unitB);

    $unitA->status = OrganizationStatus::Inactive;
    $unitA->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('context.workspace.label', 'Unit B'));
});

test('setelah keluar dan masuk lagi, pemilik dua yayasan wajib memilih ulang', function () {
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    $user = User::factory()->create(['email' => 'dua.yayasan@example.com']);
    $membershipA = CoreFixtures::member($user, $tenantA);
    CoreFixtures::member($user, $tenantB);

    $this->actingAs($user)->post(route('context.tenant.update'), ['membership_id' => $membershipA->id]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->post(route('logout'));
    $this->post(route('login.store'), ['login' => 'dua.yayasan@example.com', 'password' => 'password']);

    $this->get(route('dashboard'))->assertRedirect(route('context.tenant.edit'));
});

test('halaman pengaturan profil tetap bisa dibuka tanpa konteks kerja', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});
