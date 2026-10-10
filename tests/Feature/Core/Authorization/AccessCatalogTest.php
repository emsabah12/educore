<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Authorization\SyncAccessCatalog;
use Modules\Core\Domain\Authorization\Exceptions\AccessException;
use Modules\Core\Domain\Authorization\Permission;
use Modules\Core\Domain\Authorization\Role;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Tests\Support\CoreFixtures;

/*
 * Katalog role & permission dari kode (OD-11) dan pemasangan role (PRD-000 §5).
 */

test('sinkronisasi mengisi katalog bawaan Core dan aman diulang', function () {
    $this->artisan('educore:sync-access')->assertSuccessful();
    $this->artisan('educore:sync-access')->assertSuccessful();

    expect(Permission::query()->count())->toBe(5)
        ->and(Role::query()->count())->toBe(5)
        ->and(DB::table('role_permission')->count())->toBe(5 + 2 + 4 + 3 + 1);

    $kepala = Role::query()->where('key', CoreAccess::ROLE_KEPALA_LEMBAGA)->with('permissions')->sole();

    expect($kepala->name)->toBe('Kepala Lembaga')
        ->and($kepala->permissions->pluck('key')->sort()->values()->all())->toBe([
            CoreAccess::MEMBERSHIPS_MANAGE,
            CoreAccess::ORGANIZATIONS_VIEW,
            CoreAccess::SETTINGS_MANAGE,
            CoreAccess::SETTINGS_VIEW,
        ]);
});

test('permission yang dihapus dari kode ikut hilang dari database dan dari role', function () {
    app(AccessCatalog::class)
        ->permission('uji.laporan.lama', 'Laporan lama')
        ->grant(CoreAccess::ROLE_STAF, ['uji.laporan.lama']);
    app(SyncAccessCatalog::class)->handle();

    expect(Permission::query()->where('key', 'uji.laporan.lama')->exists())->toBeTrue();

    // Katalog baru (mis. setelah deploy) tanpa permission tersebut.
    $catalogBaru = new AccessCatalog;
    CoreAccess::register($catalogBaru);
    $this->app->instance(AccessCatalog::class, $catalogBaru);

    $result = app(SyncAccessCatalog::class)->handle();

    expect($result['removed_permissions'])->toBe(1)
        ->and(Permission::query()->where('key', 'uji.laporan.lama')->exists())->toBeFalse()
        ->and(Role::query()->where('key', CoreAccess::ROLE_STAF)->sole()->permissions()->count())->toBe(1);
});

test('format key dan rujukan katalog divalidasi di kode', function () {
    $catalog = new AccessCatalog;

    expect(fn () => $catalog->permission('HR.Employees', 'Salah'))->toThrow(AccessException::class)
        ->and(fn () => $catalog->role('Kepala Lembaga', 'Salah'))->toThrow(AccessException::class)
        ->and(fn () => $catalog->role('kepala', 'Kepala', ['hr.employees.view']))->toThrow(AccessException::class, 'Hak akses hr.employees.view belum terdaftar di katalog.')
        ->and(fn () => $catalog->grant('tidak-ada', []))->toThrow(AccessException::class, 'Role tidak-ada belum terdaftar di katalog.');
});

test('database menolak key permission dan role yang formatnya salah', function () {
    // DB::transaction di dalam transaksi test = savepoint, sehingga gagal pertama tidak merusak percobaan kedua.
    expect(fn () => DB::transaction(fn () => DB::table('permissions')->insert(['id' => (string) Str::uuid7(), 'key' => 'Salah Format', 'name' => 'x'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'Role Salah', 'name' => 'x'])))
        ->toThrow(QueryException::class);
});

test('memasang role dua kali tidak membuat baris ganda', function () {
    CoreFixtures::syncAccess();
    $tenant = CoreFixtures::tenant();
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);
    $assignment = CoreFixtures::assign($membership, CoreFixtures::unit($tenant, 'UNIT-1'));

    CoreFixtures::tenantRole($membership, CoreAccess::ROLE_ADMIN_YAYASAN);
    CoreFixtures::tenantRole($membership, CoreAccess::ROLE_ADMIN_YAYASAN);
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_STAF);
    CoreFixtures::assignmentRole($assignment, CoreAccess::ROLE_STAF);

    expect(DB::table('membership_roles')->count())->toBe(1)
        ->and(DB::table('organizational_assignment_roles')->count())->toBe(1);

    app(ManageRoles::class)->revokeTenantRole($membership, CoreAccess::ROLE_ADMIN_YAYASAN);

    expect(DB::table('membership_roles')->count())->toBe(0);
});

test('role tidak bisa dipasang ke membership nonaktif atau bila katalog belum disinkronkan', function () {
    $tenant = CoreFixtures::tenant();
    $membership = CoreFixtures::member(User::factory()->create(), $tenant);

    expect(fn () => CoreFixtures::tenantRole($membership, CoreAccess::ROLE_STAF))
        ->toThrow(AccessException::class, 'Role tidak ditemukan. Jalankan sinkronisasi katalog akses terlebih dahulu.');

    CoreFixtures::syncAccess();
    $membership->status = MembershipStatus::Inactive;
    $membership->save();

    expect(fn () => CoreFixtures::tenantRole($membership, CoreAccess::ROLE_STAF))
        ->toThrow(AccessException::class, 'Keanggotaan di yayasan ini sudah nonaktif.');
});

test('database menolak role penugasan lintas yayasan', function () {
    CoreFixtures::syncAccess();
    $tenantA = CoreFixtures::tenant('YYS-A');
    $tenantB = CoreFixtures::tenant('YYS-B');
    $assignment = CoreFixtures::assign(CoreFixtures::member(User::factory()->create(), $tenantA), CoreFixtures::unit($tenantA, 'UNIT-A'));

    expect(fn () => DB::table('organizational_assignment_roles')->insert([
        'tenant_id' => $tenantB->id,
        'organizational_assignment_id' => $assignment->id,
        'role_id' => Role::query()->where('key', CoreAccess::ROLE_STAF)->value('id'),
    ]))->toThrow(QueryException::class);
});

test('role yang masih dipasang tidak bisa dihapus dari katalog', function () {
    CoreFixtures::syncAccess();
    $tenant = CoreFixtures::tenant();
    CoreFixtures::tenantRole(CoreFixtures::member(User::factory()->create(), $tenant), CoreAccess::ROLE_PIMPINAN);

    expect(fn () => Role::query()->where('key', CoreAccess::ROLE_PIMPINAN)->delete())->toThrow(QueryException::class);
});

test('role yang dihapus dari kode dikosongkan sehingga tidak memberi hak apa pun', function () {
    app(AccessCatalog::class)->role('uji-role-lama', 'Role lama', [CoreAccess::SETTINGS_MANAGE]);
    app(SyncAccessCatalog::class)->handle();

    $catalogBaru = new AccessCatalog;
    CoreAccess::register($catalogBaru);
    $this->app->instance(AccessCatalog::class, $catalogBaru);

    $result = app(SyncAccessCatalog::class)->handle();
    $roleLama = Role::query()->where('key', 'uji-role-lama')->sole();

    expect($result['emptied_roles'])->toBe(1)
        ->and($roleLama->permissions()->count())->toBe(0);
});
