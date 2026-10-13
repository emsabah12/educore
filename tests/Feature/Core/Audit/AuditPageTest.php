<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Halaman log audit baca-saja (OD-17).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
});

/**
 * @return array<string, mixed>
 */
function auditPage(TestResponse $response): array
{
    /** @var array{props: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'];
}

/**
 * @return list<string>
 */
function auditActionsShown(TestResponse $response): array
{
    return array_values(array_unique(array_column(auditPage($response)['logs']['data'], 'action')));
}

test('Admin Yayasan membuka log audit; menu muncul dari permission core.audit.view', function () {
    $admin = DevAccounts::user('admin.yayasan');

    $this->actingAs($admin)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->where('permissions', fn ($permissions) => collect($permissions)->contains(CoreAccess::AUDIT_VIEW))
            // Aksi tingkat platform (sinkronisasi katalog, hapus log lama) tidak ditawarkan di filter yayasan.
            ->has('actions', count(AuditAction::labels()) - count(AuditAction::PLATFORM_ONLY)),
        );
});

test('tanpa izin atau tidak bekerja di tingkat Yayasan: 403', function () {
    $this->actingAs(DevAccounts::user('kepala.unit1'))->get(route('audit.index'))->assertForbidden();

    // Admin Yayasan yang sedang bekerja di lembaga kerja Unit 1, bukan Seluruh Yayasan.
    $user = User::factory()->create();
    $membership = CoreFixtures::tenantRole(CoreFixtures::member($user, $this->tenant), CoreAccess::ROLE_ADMIN_YAYASAN);
    $unit1 = CoreFixtures::assign($membership, CoreFixtures::node($this->tenant, 'PONPES-U1'));

    $this->actingAs($user)->get(route('dashboard'));
    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => $unit1->id]);
    $this->actingAs($user)->get(route('audit.index'))->assertForbidden();

    $this->actingAs($user)->post(route('context.workspace.update'), ['assignment_id' => 'tenant']);
    $this->actingAs($user)->get(route('audit.index'))->assertOk();
});

test('hanya log yayasan ini dan kejadian akun milik anggotanya yang tampil', function () {
    $orang = User::factory()->create();
    CoreFixtures::member($orang, $this->tenant);
    $orangLuar = User::factory()->create();
    CoreFixtures::unit(CoreFixtures::tenant('YYS-B'), 'UNIT-B');

    $this->post(route('login.store'), ['login' => $orang->email, 'password' => 'password']);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['login' => $orangLuar->email, 'password' => 'password']);
    $this->post(route('logout'));

    $response = $this->actingAs(DevAccounts::user('admin.yayasan'))->get(route('audit.index', ['aksi' => AuditAction::LOGIN]))->assertOk();
    $logins = auditPage($response)['logs']['data'];

    expect($logins)->toHaveCount(1)
        ->and($logins[0]['subject_user'])->toBe($orang->person->name);

    // Lembaga yayasan lain (UNIT-B) tidak ikut tampil.
    $response = $this->actingAs(DevAccounts::user('admin.yayasan'))->get(route('audit.index', ['aksi' => AuditAction::ORGANIZATION_CREATED]));
    $organizations = array_column(auditPage($response)['logs']['data'], 'organization');

    expect($organizations)->toHaveCount(23)->not->toContain('Unit UNIT-B');
});

test('filter jenis aksi, pelaku, dan tanggal', function () {
    $admin = DevAccounts::user('admin.yayasan');
    $mda = CoreFixtures::node($this->tenant, 'U2-MDA');

    $this->actingAs($admin)->patch(route('organizations.update', $mda->id), ['name' => 'MDA Al-Hikmah']);

    $byAction = $this->actingAs($admin)->get(route('audit.index', ['aksi' => AuditAction::ORGANIZATION_RENAMED]));
    expect(auditActionsShown($byAction))->toBe([AuditAction::ORGANIZATION_RENAMED])
        ->and(auditPage($byAction)['logs']['data'][0])->toMatchArray([
            'action_label' => 'Nama lembaga diubah',
            'organization' => 'MDA Al-Hikmah',
            'changes' => ['name' => ['MDA Unit 2', 'MDA Al-Hikmah']],
        ])
        ->and(auditPage($byAction)['logs']['data'][0]['actor']['name'])->toBe('Admin Yayasan');

    $byActor = $this->actingAs($admin)->get(route('audit.index', ['pelaku' => $admin->id]));
    expect(auditActionsShown($byActor))->toBe([AuditAction::ORGANIZATION_RENAMED])
        ->and(collect(auditPage($byActor)['actors'])->pluck('value'))->toContain($admin->id);

    $future = now()->addDay()->toDateString();
    $byDate = $this->actingAs($admin)->get(route('audit.index', ['dari' => $future]));
    expect(auditPage($byDate)['logs']['data'])->toBeEmpty();

    $this->actingAs($admin)
        ->get(route('audit.index', ['aksi' => 'Bukan Aksi!', 'sampai' => '2026-01-01', 'dari' => '2026-02-01']))
        ->assertSessionHasErrors(['aksi', 'sampai']);
});

test('log dipecah per 50 baris, terbaru dulu', function () {
    for ($i = 1; $i <= 60; $i++) {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id,
            'action' => 'uji.massal',
            'changes' => json_encode(['urutan' => $i]),
            'created_at' => now()->addMinutes($i),
        ]);
    }

    $admin = DevAccounts::user('admin.yayasan');
    $first = auditPage($this->actingAs($admin)->get(route('audit.index')))['logs'];

    expect($first['data'])->toHaveCount(50)
        ->and($first['data'][0]['changes'])->toBe(['urutan' => 60])
        ->and($first['next_page_url'])->not->toBeNull()
        ->and($first['total'])->toBeGreaterThan(60);
});
