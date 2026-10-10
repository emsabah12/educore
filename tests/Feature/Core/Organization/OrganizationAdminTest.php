<?php

use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Authorization\AccessScope;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Halaman admin pohon lembaga (PRD-000 §4.6, OD-14).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

function simpul(Tenant $tenant, string $code): Organization
{
    return CoreFixtures::node($tenant, $code);
}

/**
 * Props `nodes` dari halaman Inertia (kunjungan pertama, bukan request XHR).
 *
 * @return Collection<int, array<string, mixed>>
 */
function nodesOf(TestResponse $response): Collection
{
    /** @var array{props: array{nodes: list<array<string, mixed>>}} $page */
    $page = $response->viewData('page');

    return collect($page['props']['nodes']);
}

// ── Melihat ───────────────────────────────────────────────────────────────────

test('tamu diarahkan ke login dan anggota tanpa izin lihat ditolak 403', function () {
    $this->get(route('organizations.index'))->assertRedirect(route('login'));

    $user = User::factory()->create();
    CoreFixtures::member($user, $this->tenant);

    $this->actingAs($user)->get(route('organizations.index'))->assertForbidden();
});

test('Admin Yayasan melihat seluruh pohon dan boleh menambah di tingkat Yayasan', function () {
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/index')
            ->has('nodes', 23)
            ->where('canCreateRoot', true)
            ->where('showInactive', false)
            ->has('options.jenjangs', count(Jenjang::cases())),
        );
});

test('Kepala Unit 1 hanya melihat Unit 1 beserta isinya, tanpa hak kelola', function () {
    $response = $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('nodes', 6)->where('canCreateRoot', false));

    $nodes = nodesOf($response);

    expect($nodes->pluck('code')->sort()->values()->all())
        ->toBe(['PONPES-U1', 'U1-ALQURAN', 'U1-BAHASA', 'U1-MA', 'U1-MDA', 'U1-MTS'])
        ->and($nodes->where('can_manage', true))->toBeEmpty()
        // Unit 1 menjadi akar tampilan, dengan keterangan jalur induknya.
        ->and($nodes->firstWhere('code', 'PONPES-U1'))->toMatchArray(['parent_id' => null, 'path' => 'Pondok Pesantren']);
});

test('Koordinator MDA melihat ketiga MDA sebagai akar dengan jalurnya', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    $response = $this->actingAs($koordinator)->get(route('organizations.index'))->assertOk();

    expect(nodesOf($response)->pluck('path', 'code')->all())->toBe([
        'U1-MDA' => 'Pondok Pesantren › Unit 1',
        'U2-MDA' => 'Pondok Pesantren › Unit 2',
        'U3-MDA' => 'Pondok Pesantren › Unit 3',
    ]);
});

test('node nonaktif disembunyikan, kecuali diminta ditampilkan', function () {
    $mda = simpul($this->tenant, 'U3-MDA');
    $mda->status = OrganizationStatus::Inactive;
    $mda->save();

    $admin = DevAccounts::user('admin.yayasan');

    $this->actingAs($admin)->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page->has('nodes', 22));

    $this->actingAs($admin)->get(route('organizations.index', ['nonaktif' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('nodes', 23)->where('showInactive', true));
});

// ── Menambah ──────────────────────────────────────────────────────────────────

test('Admin Yayasan menambah lembaga di bawah unit dan langsung masuk cakupan koordinator', function () {
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->post(route('organizations.store'), [
            'parent_id' => simpul($this->tenant, 'PONPES-U2')->id,
            'type' => 'LEMBAGA',
            'code' => 'u2-mda-putri',
            'name' => 'MDA Putri Unit 2',
            'category' => 'NONFORMAL',
            'jenjang' => 'MDA',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $baru = simpul($this->tenant, 'U2-MDA-PUTRI');
    $koordinatorMda = app(AccessScope::class)->coverage($this->tenant->id, null, Jenjang::Mda);

    expect($baru->name)->toBe('MDA Putri Unit 2')
        ->and(isset($koordinatorMda[$baru->id]))->toBeTrue();
});

test('Admin Yayasan menambah biro langsung di bawah Yayasan', function () {
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->post(route('organizations.store'), ['type' => 'BIRO', 'code' => 'BIRO-HUKUM', 'name' => 'Biro Hukum'])
        ->assertSessionHasNoErrors();

    expect(simpul($this->tenant, 'BIRO-HUKUM')->parent_id)->toBeNull();
});

test('isian tambah lembaga divalidasi dengan pesan Bahasa Indonesia', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $unit2 = simpul($this->tenant, 'PONPES-U2')->id;

    $this->actingAs($admin)
        ->post(route('organizations.store'), ['parent_id' => $unit2, 'type' => 'LEMBAGA', 'code' => 'X', 'name' => ''])
        ->assertSessionHasErrors([
            'code' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–50 karakter.',
            'category' => 'Lembaga wajib memiliki kategori.',
            'jenjang' => 'Lembaga wajib memiliki jenjang.',
            'name',
        ]);

    $this->actingAs($admin)
        ->post(route('organizations.store'), ['parent_id' => $unit2, 'type' => 'UNIT', 'code' => 'U2-SUB', 'name' => 'Sub', 'jenjang' => 'MDA'])
        ->assertSessionHasErrors(['jenjang' => 'Jenjang hanya untuk node berjenis lembaga.']);

    // Aturan pohon dari service (kode ganda) tampil sebagai pesan form.
    $this->actingAs($admin)
        ->post(route('organizations.store'), ['parent_id' => $unit2, 'type' => 'UNIT', 'code' => 'U1-MA', 'name' => 'Duplikat'])
        ->assertSessionHasErrors(['form' => 'Kode U1-MA sudah dipakai di yayasan ini.']);
});

test('tanpa izin kelola: di dalam cakupan 403, di luar cakupan 404', function () {
    $kepalaUnit = DevAccounts::user('kepala.unit1');
    $data = ['type' => 'UNIT', 'code' => 'SUB-BARU', 'name' => 'Sub baru'];

    $this->actingAs($kepalaUnit)
        ->post(route('organizations.store'), [...$data, 'parent_id' => simpul($this->tenant, 'PONPES-U1')->id])
        ->assertForbidden();
    $this->actingAs($kepalaUnit)
        ->post(route('organizations.store'), [...$data, 'parent_id' => simpul($this->tenant, 'PONPES-U2')->id])
        ->assertNotFound();
    $this->actingAs($kepalaUnit)
        ->post(route('organizations.store'), $data)
        ->assertForbidden();

    expect(Organization::query()->where('code', 'SUB-BARU')->exists())->toBeFalse();
});

test('pemegang izin kelola di sebuah unit tidak bisa menambah di tingkat Yayasan', function () {
    $user = User::factory()->create();
    $assignment = CoreFixtures::assign(CoreFixtures::member($user, $this->tenant), simpul($this->tenant, 'PONPES-U1'));
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($user)
        ->post(route('organizations.store'), ['parent_id' => simpul($this->tenant, 'PONPES-U1')->id, 'type' => 'UNIT', 'code' => 'U1-SUB', 'name' => 'Sub Unit 1'])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)
        ->post(route('organizations.store'), ['type' => 'BIRO', 'code' => 'BIRO-X', 'name' => 'Biro X'])
        ->assertForbidden();
});

// ── Ubah nama, pindah, nonaktif/aktif ─────────────────────────────────────────

test('Admin Yayasan mengubah nama; kode tetap', function () {
    $mda = simpul($this->tenant, 'U2-MDA');

    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->patch(route('organizations.update', $mda->id), ['name' => '  MDA Al-Hikmah Unit 2 '])
        ->assertSessionHasNoErrors();

    $mda->refresh();
    expect($mda->name)->toBe('MDA Al-Hikmah Unit 2')->and($mda->code)->toBe('U2-MDA');
});

test('ubah nama tanpa izin kelola 403, lembaga di luar cakupan atau yayasan lain 404', function () {
    $tenantB = CoreFixtures::tenant('YYS-B');
    $unitB = CoreFixtures::unit($tenantB, 'UNIT-B');
    $kepalaUnit = DevAccounts::user('kepala.unit1');

    $this->actingAs($kepalaUnit)->patch(route('organizations.update', simpul($this->tenant, 'U1-MA')->id), ['name' => 'X'])->assertForbidden();
    $this->actingAs($kepalaUnit)->patch(route('organizations.update', simpul($this->tenant, 'U2-MA')->id), ['name' => 'X'])->assertNotFound();
    $this->actingAs(DevAccounts::user('admin.yayasan'))->patch(route('organizations.update', $unitB->id), ['name' => 'X'])->assertNotFound();
    $this->actingAs(DevAccounts::user('admin.yayasan'))->patch(route('organizations.update', 'bukan-uuid'), ['name' => 'X'])->assertNotFound();
});

test('Admin Yayasan memindah node; siklus ditolak dengan pesan ramah', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $mda = simpul($this->tenant, 'U1-MDA');

    $this->actingAs($admin)
        ->patch(route('organizations.move', $mda->id), ['parent_id' => simpul($this->tenant, 'PONPES-U2')->id])
        ->assertSessionHasNoErrors();
    expect($mda->refresh()->parent_id)->toBe(simpul($this->tenant, 'PONPES-U2')->id);

    $this->actingAs($admin)
        ->patch(route('organizations.move', simpul($this->tenant, 'PONPES')->id), ['parent_id' => simpul($this->tenant, 'U2-MA')->id])
        ->assertSessionHasErrors(['form' => 'Lembaga/unit tidak bisa dipindah ke bawah dirinya sendiri atau turunannya.']);

    $this->actingAs($admin)
        ->patch(route('organizations.move', $mda->id), ['parent_id' => null])
        ->assertSessionHasNoErrors();
    expect($mda->refresh()->parent_id)->toBeNull();
});

test('node tidak bisa dipindah ke luar cakupan izin kelola', function () {
    $user = User::factory()->create();
    $assignment = CoreFixtures::assign(CoreFixtures::member($user, $this->tenant), simpul($this->tenant, 'PONPES-U1'));
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($user)
        ->patch(route('organizations.move', simpul($this->tenant, 'U1-MDA')->id), ['parent_id' => simpul($this->tenant, 'PONPES-U2')->id])
        ->assertNotFound();
    $this->actingAs($user)
        ->patch(route('organizations.move', simpul($this->tenant, 'U1-MDA')->id), ['parent_id' => null])
        ->assertForbidden();

    expect(simpul($this->tenant, 'U1-MDA')->parent_id)->toBe(simpul($this->tenant, 'PONPES-U1')->id);
});

test('nonaktifkan dari bawah ke atas, aktifkan kembali dari atas ke bawah', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $unit3 = simpul($this->tenant, 'PONPES-U3');

    // Unit masih punya lembaga aktif.
    $this->actingAs($admin)
        ->post(route('organizations.deactivate', $unit3->id))
        ->assertSessionHasErrors(['form' => 'Masih ada lembaga/unit aktif di bawahnya. Nonaktifkan dari tingkat paling bawah terlebih dahulu.']);

    foreach (['U3-SMP', 'U3-SMK', 'U3-MDA', 'U3-BAHASA', 'U3-ALQURAN'] as $code) {
        $this->actingAs($admin)->post(route('organizations.deactivate', simpul($this->tenant, $code)->id))->assertSessionHasNoErrors();
    }

    $this->actingAs($admin)->post(route('organizations.deactivate', $unit3->id))->assertSessionHasNoErrors();
    expect($unit3->refresh()->isActive())->toBeFalse();

    // Lembaga di unit nonaktif belum bisa diaktifkan.
    $this->actingAs($admin)
        ->post(route('organizations.activate', simpul($this->tenant, 'U3-SMK')->id))
        ->assertSessionHasErrors(['form' => 'Induk sudah nonaktif. Aktifkan induknya atau pilih induk lain.']);

    $this->actingAs($admin)->post(route('organizations.activate', $unit3->id))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('organizations.activate', simpul($this->tenant, 'U3-SMK')->id))->assertSessionHasNoErrors();

    expect($unit3->refresh()->isActive())->toBeTrue()
        ->and(simpul($this->tenant, 'U3-SMK')->isActive())->toBeTrue()
        ->and(simpul($this->tenant, 'U3-SMP')->isActive())->toBeFalse();
});

test('menu Lembaga hanya ditawarkan kepada pemilik izin lihat', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => collect($permissions)->contains(CoreAccess::ORGANIZATIONS_VIEW)));
});

test('aksi status dan pindah: di dalam cakupan tanpa izin 403, di luar cakupan 404', function () {
    $kepalaUnit = DevAccounts::user('kepala.unit1');
    $u1Ma = simpul($this->tenant, 'U1-MA')->id;
    $u2Ma = simpul($this->tenant, 'U2-MA')->id;

    $this->actingAs($kepalaUnit)->post(route('organizations.deactivate', $u1Ma))->assertForbidden();
    $this->actingAs($kepalaUnit)->post(route('organizations.deactivate', $u2Ma))->assertNotFound();
    $this->actingAs($kepalaUnit)->post(route('organizations.activate', $u2Ma))->assertNotFound();
    $this->actingAs($kepalaUnit)->patch(route('organizations.move', $u1Ma), ['parent_id' => null])->assertForbidden();
    $this->actingAs($kepalaUnit)->patch(route('organizations.move', $u2Ma), ['parent_id' => null])->assertNotFound();

    expect(simpul($this->tenant, 'U1-MA')->isActive())->toBeTrue();
});

test('induk dari yayasan lain dijawab 404', function () {
    $unitB = CoreFixtures::unit(CoreFixtures::tenant('YYS-B'), 'UNIT-B');

    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->post(route('organizations.store'), ['parent_id' => $unitB->id, 'type' => 'UNIT', 'code' => 'SUB-B', 'name' => 'Sub B'])
        ->assertNotFound();
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->patch(route('organizations.move', simpul($this->tenant, 'U1-MA')->id), ['parent_id' => $unitB->id])
        ->assertNotFound();
});

test('lembaga kerja yang sedang dipakai tidak bisa dinonaktifkan dari dalamnya', function () {
    $user = User::factory()->create();
    $unit2 = simpul($this->tenant, 'PONPES-U2');
    $assignment = CoreFixtures::assign(CoreFixtures::member($user, $this->tenant), $unit2);
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($user)
        ->post(route('organizations.deactivate', $unit2->id))
        ->assertSessionHasErrors(['form' => 'Lembaga kerja yang sedang Anda pakai tidak bisa dinonaktifkan dari sini. Minta pengelola di tingkat atasnya.']);

    expect($unit2->refresh()->isActive())->toBeTrue();
});

test('anggota tanpa izin lihat tidak ditawari menu Lembaga', function () {
    $user = User::factory()->create();
    CoreFixtures::member($user, $this->tenant);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', []));
});
