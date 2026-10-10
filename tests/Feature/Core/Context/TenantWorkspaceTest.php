<?php

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Domain\Identity\User;
use Tests\Support\CoreFixtures;

/*
 * Pilihan lembaga kerja "Seluruh Yayasan" untuk pemegang role tenant-wide (PRD-000 §6, F3).
 */

beforeEach(fn () => CoreFixtures::syncAccess());

test('role tenant-wide + satu penugasan: wajib memilih, Seluruh Yayasan ada di urutan pertama', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'PONPES-U1', name: 'Unit 1');
    $user = User::factory()->create();
    $membership = CoreFixtures::tenantRole(CoreFixtures::member($user, $tenant), CoreAccess::ROLE_ADMIN_YAYASAN);
    CoreFixtures::assign($membership, $unit);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.workspace.edit'));

    $this->actingAs($user)
        ->get(route('context.workspace.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('context/select-workspace')
            ->has('assignments', 2)
            ->where('assignments.0.id', 'tenant')
            ->where('assignments.0.label', 'Seluruh Yayasan')
            ->where('assignments.0.type', 'tenant')
            ->where('assignments.1.label', 'Unit 1')
            ->where('assignments.1.type', 'organization'),
        );

    $this->actingAs($user)
        ->post(route('context.workspace.update'), ['assignment_id' => 'tenant'])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.type', 'tenant')
            ->where('context.workspace.label', 'Seluruh Yayasan')
            ->where('context.can_switch_workspace', true),
        );
});

test('role tenant-wide tanpa penugasan langsung masuk Seluruh Yayasan', function () {
    $tenant = CoreFixtures::tenant();
    $user = User::factory()->create();
    CoreFixtures::tenantRole(CoreFixtures::member($user, $tenant), CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.type', 'tenant')
            ->where('context.can_switch_workspace', false),
        );
});

test('tanpa role tenant-wide, Seluruh Yayasan tidak bisa dipilih bila punya penugasan', function () {
    $tenant = CoreFixtures::tenant();
    $user = User::factory()->create();
    $membership = CoreFixtures::member($user, $tenant);
    CoreFixtures::assign($membership, CoreFixtures::unit($tenant, 'UNIT-A'));
    CoreFixtures::assign($membership, CoreFixtures::unit($tenant, 'UNIT-B'));

    $this->actingAs($user)->get(route('dashboard'));

    $this->actingAs($user)
        ->post(route('context.workspace.update'), ['assignment_id' => 'tenant'])
        ->assertNotFound();
});

test('role tenant-wide dicabut saat bekerja di Seluruh Yayasan: request berikutnya pindah ke penugasannya', function () {
    $tenant = CoreFixtures::tenant();
    $unit = CoreFixtures::unit($tenant, 'PONPES-U1', name: 'Unit 1');
    $user = User::factory()->create();
    $membership = CoreFixtures::tenantRole(CoreFixtures::member($user, $tenant), CoreAccess::ROLE_ADMIN_YAYASAN);
    CoreFixtures::assign($membership, $unit);

    $this->actingAs($user)->get(route('dashboard'));
    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => 'tenant']);

    app(ManageRoles::class)->revokeTenantRole($membership, CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.workspace.type', 'organization')
            ->where('context.workspace.label', 'Unit 1'),
        );
});
