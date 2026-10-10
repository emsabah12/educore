<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\ScopedSetting;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\AuthorizationProbe;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Aturan berjenjang / scoped settings (PRD-000 §8, F3). Aturan contoh "uji.jam_masuk"
 * hanya didefinisikan di test; aturan asli didefinisikan modul HR/Academic nanti.
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    AuthorizationProbe::register();

    app(SettingRegistry::class)->define(new SettingDefinition(
        key: AuthorizationProbe::SETTING_KEY,
        label: 'Jam masuk',
        default: '07:00',
        rules: ['required', 'string', 'date_format:H:i'],
    ));

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

/**
 * @param  array<string, mixed>  $target  node, jenjang, value, enforced
 */
function tetapkanAturan(string $username, array $target): TestResponse
{
    return test()->actingAs(DevAccounts::user($username))->postJson('/_uji/aturan', ['key' => AuthorizationProbe::SETTING_KEY, ...$target]);
}

function nilaiAturan(string $username, ?string $node = null): TestResponse
{
    return test()->actingAs(DevAccounts::user($username))->getJson('/_uji/aturan/'.AuthorizationProbe::SETTING_KEY.($node === null ? '' : '?node='.$node));
}

// ── Urutan resolusi §8.2 ──────────────────────────────────────────────────────

test('tanpa nilai di mana pun: memakai default dari modul', function () {
    nilaiAturan('admin.yayasan', 'U2-MDA')->assertExactJson(['value' => '07:00', 'source' => 'default']);
    nilaiAturan('admin.yayasan')->assertExactJson(['value' => '07:00', 'source' => 'default']);
});

test('nilai dari node terdekat menang: Yayasan, lalu Unit, lalu lembaga', function () {
    tetapkanAturan('admin.yayasan', ['value' => '07:30'])->assertOk();
    nilaiAturan('admin.yayasan', 'U2-MDA')->assertExactJson(['value' => '07:30', 'source' => 'nearest']);

    tetapkanAturan('admin.yayasan', ['node' => 'PONPES-U2', 'value' => '06:45'])->assertOk();
    nilaiAturan('admin.yayasan', 'U2-MDA')->assertJson(['value' => '06:45']);
    nilaiAturan('admin.yayasan', 'U1-MA')->assertJson(['value' => '07:30']);

    tetapkanAturan('admin.yayasan', ['node' => 'U2-MDA', 'value' => '13:00'])->assertOk();
    nilaiAturan('admin.yayasan', 'U2-MDA')->assertJson(['value' => '13:00']);
    nilaiAturan('admin.yayasan', 'U2-MA')->assertJson(['value' => '06:45']);
});

test('aturan yang dikunci induk teratas tidak bisa ditimpa di bawahnya', function () {
    tetapkanAturan('admin.yayasan', ['node' => 'PONPES-U2', 'value' => '06:45'])->assertOk();
    tetapkanAturan('admin.yayasan', ['node' => 'PONPES', 'value' => '07:15', 'enforced' => true])->assertOk();

    nilaiAturan('admin.yayasan', 'U2-MDA')->assertExactJson(['value' => '07:15', 'source' => 'enforced']);

    // Menulis di bawah kunci ditolak dengan pesan yang jelas.
    tetapkanAturan('admin.yayasan', ['node' => 'U2-MA', 'value' => '06:00'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Aturan ini sudah dikunci oleh tingkat di atasnya, sehingga tidak bisa diubah di sini.']);

    // Di cabang lain (Biro) kunci Ponpes tidak berlaku.
    tetapkanAturan('admin.yayasan', ['node' => 'BIRO-HUMAS', 'value' => '08:00'])->assertOk();
    nilaiAturan('admin.yayasan', 'BIRO-HUMAS')->assertJson(['value' => '08:00']);
});

test('menyimpan ulang di target yang sama memperbarui baris yang ada', function () {
    tetapkanAturan('admin.yayasan', ['node' => 'PONPES-U1', 'value' => '06:45'])->assertOk();
    tetapkanAturan('admin.yayasan', ['node' => 'PONPES-U1', 'value' => '06:50'])->assertOk();

    expect(ScopedSetting::query()->withoutGlobalScope('tenant')->count())->toBe(1);
    nilaiAturan('admin.yayasan', 'U1-MA')->assertJson(['value' => '06:50']);
});

// ── Aturan per jenis lembaga §8.3 ─────────────────────────────────────────────

test('Koordinator MDA menetapkan aturan untuk semua MDA; unit tetap bisa menimpa yang tidak dikunci', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    tetapkanAturan('admin.yayasan', ['value' => '07:00'])->assertOk();
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    tetapkanAturan('koordinator.mda', ['jenjang' => 'MDA', 'value' => '13:00'])->assertOk();

    nilaiAturan('admin.yayasan', 'U3-MDA')->assertJson(['value' => '13:00']);
    nilaiAturan('admin.yayasan', 'U3-SMP')->assertJson(['value' => '07:00']);

    // Kepala Unit 1 menimpa untuk unitnya; aturan MDA tingkat Yayasan tidak dikunci.
    tetapkanAturan('kepala.unit1', ['node' => 'PONPES-U1', 'value' => '12:30'])->assertOk();
    nilaiAturan('admin.yayasan', 'U1-MDA')->assertJson(['value' => '12:30']);
    nilaiAturan('admin.yayasan', 'U2-MDA')->assertJson(['value' => '13:00']);
});

test('aturan MDA yang dikunci Yayasan berlaku di semua MDA walaupun unit menimpa', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    tetapkanAturan('koordinator.mda', ['jenjang' => 'MDA', 'value' => '13:00', 'enforced' => true])->assertOk();

    tetapkanAturan('kepala.unit1', ['node' => 'PONPES-U1', 'value' => '12:30'])->assertOk();

    nilaiAturan('admin.yayasan', 'U1-MDA')->assertExactJson(['value' => '13:00', 'source' => 'enforced']);
    nilaiAturan('admin.yayasan', 'U1-MA')->assertJson(['value' => '12:30']);

    // Aturan khusus MDA di Unit 1 juga tertutup oleh kunci tersebut.
    tetapkanAturan('kepala.unit1', ['node' => 'PONPES-U1', 'jenjang' => 'MDA', 'value' => '12:00'])->assertStatus(422);
});

// ── Siapa yang boleh mengubah (§8.3) ──────────────────────────────────────────

test('Koordinator MDA hanya boleh mengubah aturan di cakupan MDA', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);

    tetapkanAturan('koordinator.mda', ['node' => 'PONPES-U2', 'jenjang' => 'MDA', 'value' => '13:15'])->assertOk();
    tetapkanAturan('koordinator.mda', ['node' => 'U3-MDA', 'value' => '13:30'])->assertOk();

    // Aturan umum Yayasan: ada di dalam yayasan tapi bukan cakupannya → 403.
    tetapkanAturan('koordinator.mda', ['value' => '06:00'])->assertForbidden();
    // Aturan umum Unit 2 / jenjang lain: node di luar cakupannya → 404.
    tetapkanAturan('koordinator.mda', ['node' => 'PONPES-U2', 'value' => '06:00'])->assertNotFound();
    tetapkanAturan('koordinator.mda', ['node' => 'U1-BAHASA', 'value' => '06:00'])->assertNotFound();
    tetapkanAturan('koordinator.mda', ['jenjang' => 'BAHASA', 'value' => '06:00'])->assertForbidden();
});

test('Kepala Unit 1 hanya boleh mengubah aturan di Unit 1 dan turunannya', function () {
    tetapkanAturan('kepala.unit1', ['node' => 'PONPES-U1', 'jenjang' => 'MDA', 'value' => '12:45'])->assertOk();
    tetapkanAturan('kepala.unit1', ['node' => 'U1-MA', 'value' => '06:30'])->assertOk();

    tetapkanAturan('kepala.unit1', ['value' => '06:00'])->assertForbidden();
    tetapkanAturan('kepala.unit1', ['jenjang' => 'MDA', 'value' => '06:00'])->assertForbidden();
    tetapkanAturan('kepala.unit1', ['node' => 'PONPES-U2', 'value' => '06:00'])->assertNotFound();
    tetapkanAturan('kepala.unit1', ['node' => 'PONPES', 'value' => '06:00'])->assertNotFound();
});

test('Pimpinan hanya boleh melihat aturan, tidak mengubah', function () {
    tetapkanAturan('pimpinan.ponpes', ['node' => 'PONPES-U1', 'value' => '06:00'])->assertForbidden();
});

test('node milik yayasan lain ditolak 404', function () {
    $tenantB = Tenant::query()->create(['code' => 'YYS-B', 'name' => 'Yayasan B']);
    CoreFixtures::unit($tenantB, 'UNIT-B');

    tetapkanAturan('admin.yayasan', ['node' => 'UNIT-B', 'value' => '06:00'])->assertNotFound();
});

// ── Validasi ──────────────────────────────────────────────────────────────────

test('nilai divalidasi sesuai definisi aturan dari modul', function () {
    tetapkanAturan('admin.yayasan', ['value' => '25:99'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Nilai aturan tidak valid:') && str_contains($message, 'Jam masuk'));

    tetapkanAturan('admin.yayasan', ['value' => null])->assertStatus(422);
    expect(ScopedSetting::query()->withoutGlobalScope('tenant')->count())->toBe(0);
});

test('aturan yang belum didefinisikan modul ditolak', function () {
    test()->actingAs(DevAccounts::user('admin.yayasan'))
        ->postJson('/_uji/aturan', ['key' => 'uji.tidak_ada', 'value' => 'x'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Aturan uji.tidak_ada belum didefinisikan oleh modul mana pun.']);
});

test('database menolak nilai ganda di target yang sama', function () {
    $insert = fn () => DB::transaction(fn () => DB::table('scoped_settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'organization_id' => null,
        'jenjang' => 'MDA',
        'key' => AuthorizationProbe::SETTING_KEY,
        'value' => '"13:00"',
    ]));

    $insert();

    expect($insert)->toThrow(QueryException::class);
});

test('di tingkat yang sama, aturan jenjang terkunci lebih spesifik daripada aturan umum terkunci', function () {
    $koordinator = DevAccounts::user('koordinator.mda');
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    tetapkanAturan('koordinator.mda', ['jenjang' => 'MDA', 'value' => '13:00', 'enforced' => true])->assertOk();

    tetapkanAturan('admin.yayasan', ['value' => '07:00', 'enforced' => true])->assertOk();

    nilaiAturan('admin.yayasan', 'U1-MDA')->assertExactJson(['value' => '13:00', 'source' => 'enforced']);
    nilaiAturan('admin.yayasan', 'U1-MA')->assertExactJson(['value' => '07:00', 'source' => 'enforced']);

    // Koordinator tetap bisa mengubah aturan MDA-nya sendiri.
    DevAccounts::chooseWorkspace($this, $koordinator, null, Jenjang::Mda);
    tetapkanAturan('koordinator.mda', ['jenjang' => 'MDA', 'value' => '13:30', 'enforced' => true])->assertOk();
    nilaiAturan('admin.yayasan', 'U1-MDA')->assertJson(['value' => '13:30']);
});
