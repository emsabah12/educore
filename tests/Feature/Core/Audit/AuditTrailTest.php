<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\AuthorizationProbe;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Jejak audit (PRD-000 §5, §8.2, F4, OD-15 & OD-16).
 */

/**
 * Baris audit untuk sebuah aksi, terbaru dulu.
 *
 * @return list<object>
 */
function auditRows(string $action): array
{
    return DB::table('audit_logs')->where('action', $action)->orderByDesc('created_at')->orderByDesc('id')->get()->all();
}

/**
 * @return array<string, mixed>
 */
function auditChanges(object $row): array
{
    return is_string($row->changes) ? json_decode($row->changes, true) : [];
}

// ── Masuk & keluar ────────────────────────────────────────────────────────────

test('login berhasil dan keluar dicatat sebagai kejadian akun', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    $login = auditRows(AuditAction::LOGIN)[0];
    $logout = auditRows(AuditAction::LOGOUT)[0];

    expect($login->tenant_id)->toBeNull()
        ->and($login->actor_user_id)->toBe($user->id)
        ->and($login->subject_type)->toBe('user')
        ->and($login->subject_id)->toBe($user->id)
        ->and($login->ip)->toBe('127.0.0.1')
        ->and($logout->actor_user_id)->toBe($user->id);
});

test('login gagal dicatat tanpa kata sandi yang dicoba', function () {
    $user = User::factory()->create(['email' => 'siti@example.com']);

    $this->post(route('login.store'), ['login' => 'SITI@example.com', 'password' => 'rahasia-salah-123']);
    $this->post(route('login.store'), ['login' => 'tidak.ada', 'password' => 'rahasia-salah-456']);

    $rows = collect(auditRows(AuditAction::LOGIN_FAILED));
    $known = $rows->firstWhere('subject_id', $user->id);
    $unknown = $rows->firstWhere('subject_id', null);

    expect($rows)->toHaveCount(2);

    expect($known->subject_id)->toBe($user->id)
        ->and($known->actor_user_id)->toBeNull()
        ->and(auditChanges($known))->toBe(['login' => 'siti@example.com'])
        ->and($unknown->subject_id)->toBeNull()
        // Isian yang tidak cocok dengan akun mana pun tidak disimpan.
        ->and(auditChanges($unknown))->toBe(['akun_dikenal' => false]);

    $semua = DB::table('audit_logs')->pluck('changes')->implode(' ');
    expect($semua)->not->toContain('rahasia-salah');
});

// ── Keamanan akun ─────────────────────────────────────────────────────────────

test('ganti kata sandi, email, dan verifikasi dua langkah dicatat tanpa isi rahasianya', function () {
    $user = User::factory()->create(['email' => 'lama@example.com']);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'kata-sandi-baru',
            'password_confirmation' => 'kata-sandi-baru',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();
    $user->email = 'baru@example.com';
    $user->save();

    $user->forceFill(['two_factor_secret' => 'RAHASIA2FA', 'two_factor_confirmed_at' => now()])->save();
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    expect(auditRows(AuditAction::PASSWORD_CHANGED))->toHaveCount(1)
        ->and(auditChanges(auditRows(AuditAction::EMAIL_CHANGED)[0]))->toBe(['email' => ['lama@example.com', 'baru@example.com']])
        ->and(auditRows(AuditAction::TWO_FACTOR_ENABLED))->toHaveCount(1)
        ->and(auditRows(AuditAction::TWO_FACTOR_DISABLED))->toHaveCount(1)
        ->and(auditRows(AuditAction::PASSWORD_CHANGED)[0]->actor_user_id)->toBe($user->id);

    $semua = DB::table('audit_logs')->pluck('changes')->implode(' ');
    expect($semua)->not->toContain('kata-sandi-baru')->not->toContain('RAHASIA2FA');
});

// ── Aksi di dalam yayasan ─────────────────────────────────────────────────────

test('aksi pohon lembaga dicatat dengan pelaku, membership, lembaga, dan perubahan', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $admin = DevAccounts::user('admin.yayasan');
    $mda = CoreFixtures::node($tenant, 'U2-MDA');

    $this->actingAs($admin)
        ->patch(route('organizations.update', $mda->id), ['name' => 'MDA Al-Hikmah'])
        ->assertSessionHasNoErrors();

    $row = auditRows(AuditAction::ORGANIZATION_RENAMED)[0];
    $membership = Membership::query()->where('person_id', $admin->person_id)->sole();

    expect($row->tenant_id)->toBe($tenant->id)
        ->and($row->actor_user_id)->toBe($admin->id)
        ->and($row->actor_membership_id)->toBe($membership->id)
        ->and($row->organization_id)->toBe($mda->id)
        ->and(auditChanges($row))->toBe(['name' => ['MDA Unit 2', 'MDA Al-Hikmah']]);
});

test('seeder pohon lembaga tercatat sebagai aksi sistem', function () {
    $this->seed(FirstTenantSeeder::class);

    $rows = auditRows(AuditAction::ORGANIZATION_CREATED);

    expect($rows)->toHaveCount(23)
        ->and(collect($rows)->whereNotNull('actor_user_id'))->toBeEmpty();
});

test('perubahan aturan berjenjang mencatat nilai lama dan baru', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    AuthorizationProbe::register();
    app(SettingRegistry::class)->define(new SettingDefinition(AuthorizationProbe::SETTING_KEY, 'Jam masuk', '07:00', ['required', 'date_format:H:i']));

    $admin = DevAccounts::user('admin.yayasan');
    $this->actingAs($admin)->postJson('/_uji/aturan', ['key' => AuthorizationProbe::SETTING_KEY, 'node' => 'PONPES-U1', 'value' => '06:45'])->assertOk();
    $this->actingAs($admin)->postJson('/_uji/aturan', ['key' => AuthorizationProbe::SETTING_KEY, 'node' => 'PONPES-U1', 'value' => '06:50', 'enforced' => true])->assertOk();

    $rows = collect(auditRows(AuditAction::SETTING_CHANGED));
    $pertama = $rows->first(fn (object $row): bool => auditChanges($row)['value'][0] === null);
    $kedua = $rows->first(fn (object $row): bool => auditChanges($row)['value'][0] === '06:45');

    expect($rows)->toHaveCount(2);

    expect(auditChanges($pertama)['value'])->toBe([null, '06:45'])
        ->and(auditChanges($kedua))->toMatchArray([
            'key' => AuthorizationProbe::SETTING_KEY,
            'value' => ['06:45', '06:50'],
            'is_enforced' => [false, true],
        ])
        ->and($kedua->organization_id)->not->toBeNull();
});

test('memasang role dua kali hanya tercatat sekali; mencabut tercatat', function () {
    CoreFixtures::syncAccess();
    $membership = CoreFixtures::member(User::factory()->create(), CoreFixtures::tenant());

    CoreFixtures::tenantRole($membership, CoreAccess::ROLE_STAF);
    CoreFixtures::tenantRole($membership, CoreAccess::ROLE_STAF);
    app(ManageRoles::class)->revokeTenantRole($membership, CoreAccess::ROLE_STAF);

    expect(auditRows(AuditAction::ROLE_GRANTED))->toHaveCount(1)
        ->and(auditRows(AuditAction::ROLE_REVOKED))->toHaveCount(1)
        ->and(auditChanges(auditRows(AuditAction::ROLE_GRANTED)[0]))->toMatchArray(['role' => CoreAccess::ROLE_STAF, 'scope' => 'tenant'])
        ->and(auditRows(AuditAction::MEMBERSHIP_GRANTED))->toHaveCount(1)
        ->and(auditRows(AuditAction::CATALOG_SYNCED)[0]->tenant_id)->toBeNull();
});

test('aksi yang gagal tidak meninggalkan catatan audit', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();

    // Unit 3 masih punya lembaga aktif → ditolak.
    $this->actingAs(DevAccounts::user('admin.yayasan'))
        ->post(route('organizations.deactivate', CoreFixtures::node($tenant, 'PONPES-U3')->id))
        ->assertSessionHasErrors('form');

    expect(auditRows(AuditAction::ORGANIZATION_DEACTIVATED))->toBeEmpty();
});

// ── Append-only & masa simpan ─────────────────────────────────────────────────

test('database menolak mengubah atau menghapus audit log', function () {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->update(['action' => 'dipalsukan'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(auditRows(AuditAction::LOGIN))->toHaveCount(1);
});

test('log yang melewati masa simpan dihapus, yang baru tetap', function () {
    $insert = fn (string $action, int $daysAgo) => DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid7(),
        'action' => $action,
        'created_at' => now()->subDays($daysAgo),
    ]);

    $insert('uji.lama', 400);
    $insert('uji.baru', 10);

    $this->artisan('educore:prune-audit')->assertSuccessful();

    expect(auditRows('uji.lama'))->toBeEmpty()
        ->and(auditRows('uji.baru'))->toHaveCount(1)
        ->and(auditChanges(auditRows(AuditAction::AUDIT_PRUNED)[0]))->toBe(['days' => 365, 'deleted' => 1]);

    $this->artisan('educore:prune-audit', ['--days' => 10])->assertFailed();
});
