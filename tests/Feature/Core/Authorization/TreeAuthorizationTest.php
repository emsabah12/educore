<?php

use Illuminate\Support\Facades\Gate;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\AuthorizationProbe;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Otorisasi berbasis pohon (PRD-000 §7, F3). Skenario wajib §7.3 memakai pohon tenant pertama
 * (§4.3) dan akun uji DevAccountsSeeder. "Membuka data milik X" diwakili membuka node X
 * lewat route uji (lihat Tests\Support\AuthorizationProbe).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    AuthorizationProbe::register();

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

/** URL route uji untuk membuka data milik sebuah node. */
function bukaData(Tenant $tenant, string $code, ?string $permission = null): string
{
    $url = '/_uji/lembaga/'.CoreFixtures::node($tenant, $code)->id;

    return $permission === null ? $url : $url.'?izin='.$permission;
}

// ── Skenario wajib PRD-000 §7.3 ───────────────────────────────────────────────

test('Pimpinan Ponpes membuka data MDA Unit 2: boleh', function () {
    $this->actingAs(DevAccounts::user('pimpinan.ponpes'))
        ->get(bukaData($this->tenant, 'U2-MDA'))
        ->assertOk()
        ->assertJson(['code' => 'U2-MDA']);
});

test('Kepala Unit 1 membuka data Unit 2: 404', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(bukaData($this->tenant, 'PONPES-U2'))->assertNotFound();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(bukaData($this->tenant, 'U2-MA'))->assertNotFound();
});

test('Kepala MDA Unit 1 membuka data tingkat Ponpes atau Unit 1: 404', function () {
    $kepalaMda = User::factory()->create();
    $assignment = CoreFixtures::assign(CoreFixtures::member($kepalaMda, $this->tenant), CoreFixtures::node($this->tenant, 'U1-MDA'));
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_KEPALA_LEMBAGA);

    $this->actingAs($kepalaMda)->get(bukaData($this->tenant, 'U1-MDA'))->assertOk();
    $this->actingAs($kepalaMda)->get(bukaData($this->tenant, 'PONPES'))->assertNotFound();
    $this->actingAs($kepalaMda)->get(bukaData($this->tenant, 'PONPES-U1'))->assertNotFound();
});

test('Kepala SMK membuka data apa pun di luar SMK-nya (Ponpes, unit, lembaga sebelah): 404', function () {
    $kepalaSmk = DevAccounts::user('kepala.smk');

    $this->actingAs($kepalaSmk)->get(bukaData($this->tenant, 'U3-SMK'))->assertOk();

    foreach (['PONPES', 'PONPES-U3', 'U3-SMP', 'U1-MA', 'BIRO-PENDIDIKAN'] as $code) {
        $this->actingAs($kepalaSmk)->get(bukaData($this->tenant, $code))->assertNotFound();
    }
});

test('Kepala Unit 1 membuka data lembaga formal yang berada di Unit 1: boleh', function () {
    // Di pohon tenant pertama, lembaga formal Unit 1 adalah MTs dan MA (§4.3).
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(bukaData($this->tenant, 'U1-MTS'))->assertOk();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(bukaData($this->tenant, 'U1-MA'))->assertOk();
});

test('Koordinator MDA (fungsional, filter MDA) membuka data MDA Unit 3: boleh', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)->get(bukaData($this->tenant, 'U3-MDA'))->assertOk();
    $this->actingAs($koordinator)
        ->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)
        ->assertExactJson(['U1-MDA', 'U2-MDA', 'U3-MDA']);
});

test('Koordinator MDA membuka data Bahasa Unit 1 atau SMP: 404', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $this->actingAs($koordinator)->get(bukaData($this->tenant, 'U1-BAHASA'))->assertNotFound();
    $this->actingAs($koordinator)->get(bukaData($this->tenant, 'U3-SMP'))->assertNotFound();
});

test('MDA baru di Unit 2 langsung masuk cakupan Koordinator MDA tanpa penugasan ulang', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $mdaBaru = CoreFixtures::lembaga($this->tenant, 'U2-MDA-PUTRI', Jenjang::Mda, CoreFixtures::node($this->tenant, 'PONPES-U2'));

    $this->actingAs($koordinator)->get('/_uji/lembaga/'.$mdaBaru->id)->assertOk();
    $this->actingAs($koordinator)
        ->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)
        ->assertExactJson(['U1-MDA', 'U2-MDA', 'U2-MDA-PUTRI', 'U3-MDA']);
});

test('Admin yayasan A membuka data yayasan B: 404', function () {
    $tenantB = CoreFixtures::tenant('YYS-B');
    $unitB = CoreFixtures::unit($tenantB, 'UNIT-B');

    $this->actingAs(DevAccounts::user('admin.yayasan'))->get('/_uji/lembaga/'.$unitB->id)->assertNotFound();
    // Walaupun filter yayasan otomatis terlewati, Gate tetap menjawab 404.
    $this->actingAs(DevAccounts::user('admin.yayasan'))->get('/_uji/lembaga/'.$unitB->id.'?lintas=1')->assertNotFound();
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)
        ->assertJsonMissing(['UNIT-B']);
});

test('penugasan dicabut saat sesi masih aktif: request berikutnya ditolak', function () {
    $kepalaUnit = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepalaUnit)->get(bukaData($this->tenant, 'U1-MA'))->assertOk();

    $assignment = OrganizationalAssignment::query()
        ->whereHas('membership', fn ($membership) => $membership->where('person_id', $kepalaUnit->person_id))
        ->sole();
    $assignment->status = AssignmentStatus::Inactive;
    $assignment->save();

    $this->actingAs($kepalaUnit)->get(bukaData($this->tenant, 'U1-MA'))->assertNotFound();
});

test('role dicabut saat sesi masih aktif: request berikutnya ditolak', function () {
    $kepalaUnit = DevAccounts::user('kepala.unit1');
    $this->actingAs($kepalaUnit)->get(bukaData($this->tenant, 'U1-MA'))->assertOk();

    $assignment = OrganizationalAssignment::query()
        ->whereHas('membership', fn ($membership) => $membership->where('person_id', $kepalaUnit->person_id))
        ->sole();
    app(ManageRoles::class)->revokeAssignmentRole($assignment, CoreAccess::ROLE_KEPALA_LEMBAGA);

    $this->actingAs($kepalaUnit)->get(bukaData($this->tenant, 'U1-MA'))->assertNotFound();
});

// ── Aturan tambahan F3 ────────────────────────────────────────────────────────

test('admin yayasan dengan role tenant-wide melihat seluruh pohon', function () {
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)
        ->assertOk()
        ->assertJsonCount(23);
});

test('di dalam cakupan tetapi tanpa izin aksi: 403, bukan 404', function () {
    // Pimpinan hanya boleh melihat; mengelola pohon tidak termasuk role-nya.
    $this->actingAs(DevAccounts::user('pimpinan.ponpes'))
        ->get(bukaData($this->tenant, 'U1-MA', CoreAccess::ORGANIZATIONS_MANAGE))
        ->assertForbidden();

    // Di luar cakupan tetap 404 walaupun aksinya juga tidak diizinkan.
    $this->actingAs(DevAccounts::user('pimpinan.ponpes'))
        ->get(bukaData($this->tenant, 'BIRO-HUMAS', CoreAccess::ORGANIZATIONS_MANAGE))
        ->assertNotFound();
});

test('lembaga kerja aktif membatasi data walaupun role lebih luas', function () {
    $user = User::factory()->create();
    $membership = CoreFixtures::tenantRole(CoreFixtures::member($user, $this->tenant), CoreAccess::ROLE_ADMIN_YAYASAN);
    $unit1 = CoreFixtures::assign($membership, CoreFixtures::node($this->tenant, 'PONPES-U1'));
    CoreFixtures::assignmentRole($unit1, CoreAccess::ROLE_STAF);

    // Punya role tenant-wide + satu penugasan → wajib memilih: Seluruh Yayasan atau Unit 1.
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('context.workspace.edit'));

    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => $unit1->id]);
    $this->actingAs($user)->get(bukaData($this->tenant, 'U1-MA'))->assertOk();
    $this->actingAs($user)->get(bukaData($this->tenant, 'U2-MA'))->assertNotFound();

    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => 'tenant']);
    $this->actingAs($user)->get(bukaData($this->tenant, 'U2-MA'))->assertOk();
});

test('koordinator di lembaga kerja Biro hanya melihat Biro-nya', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, 'BIRO-PENDIDIKAN');

    $this->actingAs($koordinator)
        ->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)
        ->assertExactJson(['BIRO-PENDIDIKAN']);
    $this->actingAs($koordinator)->get(bukaData($this->tenant, 'U1-MDA'))->assertNotFound();
});

test('superadmin tidak otomatis menembus data yayasan', function () {
    $superadmin = User::factory()->superadmin()->create();
    CoreFixtures::member($superadmin, $this->tenant);

    // Anggota tanpa role: masuk Seluruh Yayasan (OD-09) tetapi tidak melihat apa pun.
    $this->actingAs($superadmin)->get(bukaData($this->tenant, 'PONPES'))->assertNotFound();
    $this->actingAs($superadmin)->get('/_uji/terlihat/'.CoreAccess::ORGANIZATIONS_VIEW)->assertExactJson([]);
});

test('anggota tanpa penugasan dan tanpa role tidak melihat data apa pun', function () {
    $user = User::factory()->create();
    CoreFixtures::member($user, $this->tenant);

    $this->actingAs($user)->get(bukaData($this->tenant, 'BIRO-HUMAS'))->assertNotFound();
});

test('daftar permission di lembaga kerja dikirim ke browser sebagai petunjuk menu', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('permissions', [
            CoreAccess::MEMBERSHIPS_MANAGE,
            CoreAccess::ORGANIZATIONS_VIEW,
            CoreAccess::SETTINGS_MANAGE,
            CoreAccess::SETTINGS_VIEW,
        ]));
});

test('halaman tanpa konteks kerja tidak membawa daftar permission', function () {
    $this->actingAs(DevAccounts::user('superadmin'))
        ->get(route('platform.home'))
        ->assertInertia(fn ($page) => $page->where('permissions', []));
});

test('gate untuk ability di luar katalog tetap memakai aturan Laravel biasa', function () {
    Gate::define('uji.ability-biasa', fn (User $user): bool => $user->username === 'guru');

    expect(Gate::forUser(DevAccounts::user('guru'))->allows('uji.ability-biasa'))->toBeTrue()
        ->and(Gate::forUser(DevAccounts::user('kepala.unit1'))->allows('uji.ability-biasa'))->toBeFalse();
});

test('permission katalog ditolak di luar konteks kerja (mis. konsol)', function () {
    expect(Gate::forUser(DevAccounts::user('admin.yayasan'))->allows(CoreAccess::ORGANIZATIONS_VIEW))->toBeFalse();
});

test('gate dengan nama class (tanpa data tertentu) = punya izin di setidaknya satu node', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get('/_uji/izin-umum/'.CoreAccess::SETTINGS_MANAGE)
        ->assertExactJson(['allowed' => true]);
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get('/_uji/izin-umum/'.CoreAccess::ORGANIZATIONS_MANAGE)
        ->assertExactJson(['allowed' => false]);
});
