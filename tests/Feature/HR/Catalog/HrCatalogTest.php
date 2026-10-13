<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Catalog\CreateCatalogItem;
use Modules\HR\Application\Catalog\UpdateCatalogItem;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\EmploymentType;
use Modules\HR\Domain\Catalog\Exceptions\CatalogException;
use Modules\HR\Domain\Catalog\Position;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Katalog SDM (PRD-001 §4.3, §6): isi awal, halaman, dan aturan kodenya.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(HrCatalogSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

function hrPosition(Tenant $tenant, string $code): Position
{
    return Position::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', $code)->firstOrFail();
}

function hrAuditCount(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

// ── Isi awal ──────────────────────────────────────────────────────────────────

test('isi awal katalog terisi untuk setiap yayasan dan aman dijalankan berulang', function () {
    expect(EmploymentType::query()->count())->toBe(3)
        ->and(CatalogKind::Classification->query()->count())->toBe(4)
        ->and(Position::query()->count())->toBe(9)
        ->and(hrAuditCount(HrAuditAction::CATALOG_CREATED))->toBe(16);

    // Admin SDM sudah mengubah nama dan menonaktifkan sebuah item; seeder tidak menimpanya.
    $guru = hrPosition($this->tenant, 'GURU');
    app(UpdateCatalogItem::class)->rename($this->tenant->id, CatalogKind::Position, $guru->id, 'Guru Mata Pelajaran');
    app(UpdateCatalogItem::class)->setActive($this->tenant->id, CatalogKind::Position, $guru->id, false);

    $this->seed(HrCatalogSeeder::class);

    expect(Position::query()->count())->toBe(9)
        ->and($guru->refresh()->name)->toBe('Guru Mata Pelajaran')
        ->and($guru->is_active)->toBeFalse()
        ->and(hrAuditCount(HrAuditAction::CATALOG_CREATED))->toBe(16);

    // Yayasan baru ikut mendapat isi awal.
    CoreFixtures::tenant('YYS-BARU');
    $this->seed(HrCatalogSeeder::class);

    expect(Position::query()->count())->toBe(18);
});

// ── Halaman ───────────────────────────────────────────────────────────────────

test('Admin Yayasan membuka Katalog SDM; menu muncul dari permission hr.catalog.manage', function () {
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get(route('hr.catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/catalog')
            ->where('activeKind', CatalogKind::EmploymentType->value)
            ->has('kinds', 3)
            ->where('kinds.2.count', 9)
            ->has('items', 3)
            ->where('permissions', fn ($permissions) => collect($permissions)->contains(HrAccess::CATALOG_MANAGE)),
        );

    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->get(route('hr.catalog.index', ['jenis' => CatalogKind::Position->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeKind', CatalogKind::Position->value)
            ->has('items', 9)
            ->hasAll(['items.0.id', 'items.0.code', 'items.0.name', 'items.0.is_active']),
        );
});

test('Admin SDM (role baru) bisa mengelola katalog', function () {
    $user = User::factory()->create();
    CoreFixtures::tenantRole(CoreFixtures::member($user, $this->tenant), HrAccess::ROLE_ADMIN_SDM);

    $this->actingAs($user)->get(route('hr.catalog.index'))->assertOk();
    $this->actingAs($user)
        ->post(route('hr.catalog.store', ['kind' => CatalogKind::Position->value]), ['code' => 'guru-bk', 'name' => 'Guru BK'])
        ->assertSessionHasNoErrors();

    expect(hrPosition($this->tenant, 'GURU-BK')->name)->toBe('Guru BK');
});

test('tamu diarahkan ke login; tanpa izin kelola katalog: 403', function () {
    $this->get(route('hr.catalog.index'))->assertRedirect(route('login'));

    // Kepala lembaga hanya melihat pegawai, tidak mengelola katalog (PRD-001 §5.2).
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('hr.catalog.index'))->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->post(route('hr.catalog.store', ['kind' => CatalogKind::Position->value]), ['code' => 'BARU', 'name' => 'Baru'])
        ->assertForbidden();

    // Akun guru punya dua penugasan, jadi wajib memilih lembaga kerja dulu (OD-08).
    $guru = hrPosition($this->tenant, 'GURU');
    $akunGuru = DevAccounts::user('guru');
    DevAccounts::chooseWorkspace($this, $akunGuru, 'U1-MA');

    $this->actingAs($akunGuru)
        ->patch(route('hr.catalog.update', ['kind' => CatalogKind::Position->value, 'item' => $guru->id]), ['name' => 'Diubah'])
        ->assertForbidden();

    expect($guru->refresh()->name)->toBe('Guru');
});

// ── Mengelola ─────────────────────────────────────────────────────────────────

test('menambah, mengubah nama, menonaktifkan, dan mengaktifkan kembali tercatat di audit log', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $kind = CatalogKind::Position->value;

    $this->actingAs($admin)
        ->post(route('hr.catalog.store', ['kind' => $kind]), ['code' => ' guru-bk ', 'name' => ' Guru BK '])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $item = hrPosition($this->tenant, 'GURU-BK');
    expect($item->name)->toBe('Guru BK')->and($item->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('hr.catalog.update', ['kind' => $kind, 'item' => $item->id]), ['name' => 'Guru Bimbingan Konseling'])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)
        ->post(route('hr.catalog.deactivate', ['kind' => $kind, 'item' => $item->id]))
        ->assertSessionHasNoErrors();

    expect($item->refresh()->name)->toBe('Guru Bimbingan Konseling')
        ->and($item->is_active)->toBeFalse()
        ->and($item->code)->toBe('GURU-BK');

    $this->actingAs($admin)
        ->post(route('hr.catalog.activate', ['kind' => $kind, 'item' => $item->id]))
        ->assertSessionHasNoErrors();

    expect($item->refresh()->is_active)->toBeTrue();

    // Urutan tidak dicek: beberapa baris bisa punya created_at yang sama dalam satu test.
    $log = DB::table('audit_logs')->where('subject_id', $item->id)->pluck('action')->all();

    expect($log)->toEqualCanonicalizing([
        HrAuditAction::CATALOG_CREATED,
        HrAuditAction::CATALOG_RENAMED,
        HrAuditAction::CATALOG_DEACTIVATED,
        HrAuditAction::CATALOG_ACTIVATED,
    ])
        ->and(DB::table('audit_logs')->where('subject_id', $item->id)->value('actor_user_id'))->toBe($admin->id)
        ->and(AuditAction::label(HrAuditAction::CATALOG_CREATED))->toBe('Katalog SDM ditambahkan');
});

test('kode dobel atau format salah ditolak dengan pesan Bahasa Indonesia', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $url = route('hr.catalog.store', ['kind' => CatalogKind::Position->value]);

    $this->actingAs($admin)->post($url, ['code' => 'guru', 'name' => 'Guru Lagi'])
        ->assertSessionHasErrors(['code' => 'Kode GURU sudah dipakai di katalog jabatan.']);
    $this->actingAs($admin)->post($url, ['code' => 'G', 'name' => 'Satu Huruf'])
        ->assertSessionHasErrors('code');
    $this->actingAs($admin)->post($url, ['code' => 'GURU AGAMA', 'name' => 'Pakai Spasi'])
        ->assertSessionHasErrors('code');
    $this->actingAs($admin)->post($url, ['code' => 'BARU', 'name' => ''])
        ->assertSessionHasErrors('name');
    $this->actingAs($admin)->post($url, ['code' => 'BARU', 'name' => str_repeat('a', 101)])
        ->assertSessionHasErrors('name');

    expect(Position::query()->count())->toBe(9);
});

test('kode yang sama boleh dipakai di katalog lain dan di yayasan lain', function () {
    $create = app(CreateCatalogItem::class);

    $create->handle($this->tenant->id, CatalogKind::EmploymentType, 'GURU', 'Guru (jenis)');
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $create->handle($tenantLain->id, CatalogKind::Position, 'GURU', 'Guru');

    expect(fn () => $create->handle($tenantLain->id, CatalogKind::Position, 'guru', 'Guru lagi'))
        ->toThrow(CatalogException::class, 'Kode GURU sudah dipakai di katalog jabatan.');
});

test('item milik yayasan lain, ID asal-asalan, atau jenis katalog tak dikenal: 404', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $tenantLain = CoreFixtures::tenant('YYS-LAIN');
    $itemLain = app(CreateCatalogItem::class)->handle($tenantLain->id, CatalogKind::Position, 'RAHASIA', 'Jabatan Yayasan Lain');

    $this->actingAs($admin)
        ->patch(route('hr.catalog.update', ['kind' => CatalogKind::Position->value, 'item' => $itemLain->id]), ['name' => 'Dibajak'])
        ->assertNotFound();
    $this->actingAs($admin)
        ->post(route('hr.catalog.deactivate', ['kind' => CatalogKind::Position->value, 'item' => 'bukan-uuid']))
        ->assertNotFound();

    // Item ada, tetapi di katalog jenis lain.
    $guru = hrPosition($this->tenant, 'GURU');
    $this->actingAs($admin)
        ->post(route('hr.catalog.deactivate', ['kind' => CatalogKind::EmploymentType->value, 'item' => $guru->id]))
        ->assertNotFound();

    $this->actingAs($admin)->post('/pegawai/katalog/golongan', ['code' => 'III-A', 'name' => 'III/a'])->assertNotFound();

    // Jenis tak dikenal di halaman daftar: kembali ke katalog pertama.
    $this->actingAs($admin)
        ->get(route('hr.catalog.index', ['jenis' => 'golongan']))
        ->assertInertia(fn (Assert $page) => $page->where('activeKind', CatalogKind::EmploymentType->value));

    expect($itemLain->refresh()->name)->toBe('Jabatan Yayasan Lain')
        ->and($guru->refresh()->is_active)->toBeTrue();
});
