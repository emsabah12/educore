<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Organization\CreateOrganization;
use Modules\Core\Application\Organization\DeactivateOrganization;
use Modules\Core\Application\Organization\MoveOrganization;
use Modules\Core\Application\Organization\NewOrganizationData;
use Modules\Core\Application\Organization\OrganizationTree;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationCategory;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\Tenant;

/*
 * Kriteria selesai F1 (PRD-000 §10): closure benar, siklus & kedalaman ditolak,
 * isolasi tenant, seeder tenant pertama sesuai §4.3.
 */

function treeTestTenant(string $code = 'YYS-A'): Tenant
{
    return Tenant::query()->create(['code' => $code, 'name' => "Yayasan {$code}"]);
}

function treeTestNode(
    Tenant $tenant,
    string $code,
    ?Organization $parent = null,
    OrganizationType $type = OrganizationType::Unit,
    ?OrganizationCategory $category = null,
    ?Jenjang $jenjang = null,
): Organization {
    return app(CreateOrganization::class)->handle(new NewOrganizationData(
        tenantId: $tenant->id,
        parentId: $parent?->id,
        type: $type,
        code: $code,
        name: "Node {$code}",
        category: $category,
        jenjang: $jenjang,
    ));
}

/**
 * Rantai node bertingkat: level 1 sampai $levels.
 *
 * @return list<Organization>
 */
function treeTestChain(Tenant $tenant, string $prefix, int $levels): array
{
    $chain = [];
    $parent = null;

    for ($level = 1; $level <= $levels; $level++) {
        $parent = treeTestNode($tenant, "{$prefix}{$level}", $parent);
        $chain[] = $parent;
    }

    return $chain;
}

function treeTestMove(Tenant $tenant, Organization $node, ?Organization $newParent): Organization
{
    return app(MoveOrganization::class)->handle($tenant->id, $node->id, $newParent?->id);
}

/**
 * @return list<string>
 */
function treeTestIds(Organization ...$nodes): array
{
    return array_map(fn (Organization $node): string => $node->id, $nodes);
}

// ── Closure table ─────────────────────────────────────────────────────────────

test('node akar hanya punya baris closure dirinya sendiri', function () {
    $tenant = treeTestTenant();
    $root = treeTestNode($tenant, 'ROOT');

    $rows = DB::table('organization_closure')->where('descendant_id', $root->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->ancestor_id)->toBe($root->id)
        ->and((int) $rows[0]->depth)->toBe(0);
});

test('node baru mendapat jalur ke semua induknya dengan jarak yang benar', function () {
    $tenant = treeTestTenant();
    [$a, $b, $c] = treeTestChain($tenant, 'N', 3);
    $tree = app(OrganizationTree::class);

    expect($tree->ancestorIds($tenant->id, $c->id))->toBe(treeTestIds($c, $b, $a))
        ->and($tree->descendantIds($tenant->id, $a->id))->toBe(treeTestIds($a, $b, $c))
        ->and($tree->levelOf($tenant->id, $c->id))->toBe(3)
        ->and($tree->subtreeHeight($tenant->id, $a->id))->toBe(2);

    $depthAtoC = DB::table('organization_closure')
        ->where('ancestor_id', $a->id)
        ->where('descendant_id', $c->id)
        ->value('depth');

    expect((int) $depthAtoC)->toBe(2);
});

// ── Kedalaman maksimum (OD-03) ────────────────────────────────────────────────

test('tingkat ke-6 diizinkan, tingkat ke-7 ditolak', function () {
    $tenant = treeTestTenant();
    $chain = treeTestChain($tenant, 'L', 6);

    expect(app(OrganizationTree::class)->levelOf($tenant->id, $chain[5]->id))->toBe(6);

    expect(fn () => treeTestNode($tenant, 'L7', $chain[5]))
        ->toThrow(OrganizationTreeException::class, 'Pohon lembaga maksimal 6 tingkat.');
});

// ── Isolasi tenant ────────────────────────────────────────────────────────────

test('induk dari tenant lain ditolak oleh service', function () {
    $tenantA = treeTestTenant('YYS-A');
    $tenantB = treeTestTenant('YYS-B');
    $nodeInA = treeTestNode($tenantA, 'UNIT-A');

    expect(fn () => treeTestNode($tenantB, 'UNIT-B', $nodeInA))
        ->toThrow(OrganizationTreeException::class, 'Induk tidak ditemukan di yayasan ini.');
});

test('database menolak induk dari tenant lain walaupun service dilewati', function () {
    $tenantA = treeTestTenant('YYS-A');
    $tenantB = treeTestTenant('YYS-B');
    $nodeInA = treeTestNode($tenantA, 'UNIT-A');

    expect(fn () => DB::table('organizations')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantB->id,
        'parent_id' => $nodeInA->id,
        'type' => 'UNIT',
        'code' => 'NAKAL',
        'name' => 'Node nakal',
        'status' => 'ACTIVE',
    ]))->toThrow(QueryException::class);
});

test('kode unik per tenant, tetapi boleh sama di tenant lain', function () {
    $tenantA = treeTestTenant('YYS-A');
    $tenantB = treeTestTenant('YYS-B');
    treeTestNode($tenantA, 'UNIT-1');

    expect(fn () => treeTestNode($tenantA, 'unit-1'))
        ->toThrow(OrganizationTreeException::class, 'Kode UNIT-1 sudah dipakai di yayasan ini.');

    expect(treeTestNode($tenantB, 'UNIT-1')->code)->toBe('UNIT-1');
});

test('tenant yang tidak ada ditolak dengan pesan ramah', function () {
    expect(fn () => app(CreateOrganization::class)->handle(new NewOrganizationData(
        tenantId: (string) Str::uuid7(),
        parentId: null,
        type: OrganizationType::Unit,
        code: 'UNIT-X',
        name: 'Unit X',
    )))->toThrow(OrganizationTreeException::class, 'Yayasan tidak ditemukan.');
});

// ── Validasi node (PRD-000 §4.5) ─────────────────────────────────────────────

test('kode diubah ke huruf besar dan formatnya divalidasi', function () {
    $tenant = treeTestTenant();

    expect(treeTestNode($tenant, '  unit-1 ')->code)->toBe('UNIT-1');

    expect(fn () => treeTestNode($tenant, 'unit 2!'))
        ->toThrow(OrganizationTreeException::class, 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–50 karakter.');
});

test('lembaga wajib punya kategori dan jenjang, unit dan biro tidak boleh', function () {
    $tenant = treeTestTenant();

    expect(fn () => treeTestNode($tenant, 'MA-1', type: OrganizationType::Lembaga))
        ->toThrow(OrganizationTreeException::class, 'Lembaga wajib memiliki kategori dan jenjang.');

    expect(fn () => treeTestNode($tenant, 'UNIT-1', category: OrganizationCategory::Formal))
        ->toThrow(OrganizationTreeException::class, 'Kategori dan jenjang hanya untuk node berjenis lembaga.');

    $lembaga = treeTestNode($tenant, 'MA-1', type: OrganizationType::Lembaga, category: OrganizationCategory::Formal, jenjang: Jenjang::Ma);

    expect($lembaga->jenjang)->toBe(Jenjang::Ma);
});

test('node baru tidak bisa dibuat di bawah induk nonaktif', function () {
    $tenant = treeTestTenant();
    $parent = treeTestNode($tenant, 'UNIT-1');
    app(DeactivateOrganization::class)->handle($tenant->id, $parent->id);

    expect(fn () => treeTestNode($tenant, 'UNIT-1A', $parent))
        ->toThrow(OrganizationTreeException::class, 'Induk sudah nonaktif. Aktifkan induknya atau pilih induk lain.');
});

// ── Pindah node ───────────────────────────────────────────────────────────────

test('node tidak bisa dipindah ke bawah dirinya sendiri atau turunannya', function () {
    $tenant = treeTestTenant();
    [$a, $b] = treeTestChain($tenant, 'N', 2);
    $cycleMessage = 'Lembaga/unit tidak bisa dipindah ke bawah dirinya sendiri atau turunannya.';

    expect(fn () => treeTestMove($tenant, $a, $b))->toThrow(OrganizationTreeException::class, $cycleMessage);
    expect(fn () => treeTestMove($tenant, $a, $a))->toThrow(OrganizationTreeException::class, $cycleMessage);
});

test('memindah node ikut memperbarui closure seluruh turunannya', function () {
    $tenant = treeTestTenant();
    $oldParent = treeTestNode($tenant, 'LAMA');
    $newParent = treeTestNode($tenant, 'BARU');
    $x = treeTestNode($tenant, 'NODE-X', $oldParent);
    $y = treeTestNode($tenant, 'NODE-Y', $x);
    $tree = app(OrganizationTree::class);

    treeTestMove($tenant, $x, $newParent);

    expect($tree->ancestorIds($tenant->id, $y->id))->toBe(treeTestIds($y, $x, $newParent))
        ->and($tree->descendantIds($tenant->id, $oldParent->id))->toBe(treeTestIds($oldParent))
        ->and($tree->isDescendantOrSelf($tenant->id, $newParent->id, $y->id))->toBeTrue()
        ->and($x->fresh()?->parent_id)->toBe($newParent->id);
});

test('node bisa dipindah langsung ke bawah yayasan', function () {
    $tenant = treeTestTenant();
    $parent = treeTestNode($tenant, 'INDUK');
    $x = treeTestNode($tenant, 'NODE-X', $parent);
    $y = treeTestNode($tenant, 'NODE-Y', $x);

    treeTestMove($tenant, $x, null);

    expect(app(OrganizationTree::class)->ancestorIds($tenant->id, $y->id))->toBe(treeTestIds($y, $x))
        ->and($x->fresh()?->parent_id)->toBeNull();
});

test('pemindahan ditolak bila hasilnya melebihi 6 tingkat dan closure tidak berubah', function () {
    $tenant = treeTestTenant();
    $deepChain = treeTestChain($tenant, 'A', 5);
    [$b1, $b2] = treeTestChain($tenant, 'B', 2);

    // B1 akan berada di level 6 dan B2 di level 7.
    expect(fn () => treeTestMove($tenant, $b1, $deepChain[4]))
        ->toThrow(OrganizationTreeException::class, 'Pohon lembaga maksimal 6 tingkat.');

    expect(app(OrganizationTree::class)->ancestorIds($tenant->id, $b2->id))->toBe(treeTestIds($b2, $b1))
        ->and($b1->fresh()?->parent_id)->toBeNull();
});

test('node tidak bisa dipindah ke induk milik tenant lain', function () {
    $tenantA = treeTestTenant('YYS-A');
    $tenantB = treeTestTenant('YYS-B');
    $nodeInA = treeTestNode($tenantA, 'UNIT-A');
    $nodeInB = treeTestNode($tenantB, 'UNIT-B');

    expect(fn () => treeTestMove($tenantB, $nodeInB, $nodeInA))
        ->toThrow(OrganizationTreeException::class, 'Induk tidak ditemukan di yayasan ini.');
});

// ── Nonaktifkan node ──────────────────────────────────────────────────────────

test('node dengan anak aktif tidak bisa dinonaktifkan; dari bawah ke atas boleh', function () {
    $tenant = treeTestTenant();
    [$a, $b] = treeTestChain($tenant, 'N', 2);
    $deactivate = app(DeactivateOrganization::class);

    expect(fn () => $deactivate->handle($tenant->id, $a->id))
        ->toThrow(OrganizationTreeException::class, 'Masih ada lembaga/unit aktif di bawahnya. Nonaktifkan dari tingkat paling bawah terlebih dahulu.');

    $deactivate->handle($tenant->id, $b->id);
    $deactivate->handle($tenant->id, $a->id);

    expect($a->fresh()?->status)->toBe(OrganizationStatus::Inactive)
        ->and($b->fresh()?->status)->toBe(OrganizationStatus::Inactive);
});

// ── Seeder tenant pertama (PRD-000 §4.3) ──────────────────────────────────────

test('seeder tenant pertama membentuk pohon sesuai PRD-000 §4.3', function () {
    $this->seed(FirstTenantSeeder::class);

    $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $tree = app(OrganizationTree::class);
    $idOf = fn (string $code): string => Organization::query()
        ->where('tenant_id', $tenant->id)
        ->where('code', $code)
        ->valueOrFail('id');
    $codesOf = fn (array $ids): array => Organization::query()
        ->whereIn('id', $ids)
        ->orderBy('code')
        ->pluck('code')
        ->all();

    // 4 biro + Ponpes + 3 unit + (2 formal + 3 nonformal) × 3 unit = 23 node.
    expect(Organization::query()->where('tenant_id', $tenant->id)->count())->toBe(23);

    // Biro dan Ponpes langsung di bawah Yayasan.
    expect(Organization::query()->where('tenant_id', $tenant->id)->whereNull('parent_id')->orderBy('code')->pluck('code')->all())
        ->toBe(['BIRO-HUMAS', 'BIRO-IT', 'BIRO-PENDIDIKAN', 'BIRO-SDM-KEU', 'PONPES']);

    // Pimpinan Ponpes melihat dirinya + 3 unit + 15 lembaga.
    expect($tree->descendantIds($tenant->id, $idOf('PONPES')))->toHaveCount(19);

    // Unit 3 berisi SMP & SMK (formal) + MDA, Bahasa, Al-Qur'an.
    expect($codesOf($tree->descendantIds($tenant->id, $idOf('PONPES-U3'))))
        ->toBe(['PONPES-U3', 'U3-ALQURAN', 'U3-BAHASA', 'U3-MDA', 'U3-SMK', 'U3-SMP']);

    // MA Unit 1 berada di bawah Unit 1 dan Ponpes.
    expect($tree->ancestorIds($tenant->id, $idOf('U1-MA')))
        ->toBe([$idOf('U1-MA'), $idOf('PONPES-U1'), $idOf('PONPES')]);

    // MA Unit 1 dan MA Unit 2 tidak berelasi.
    expect($tree->isDescendantOrSelf($tenant->id, $idOf('U1-MA'), $idOf('U2-MA')))->toBeFalse();

    $maUnit1 = Organization::query()->findOrFail($idOf('U1-MA'));
    expect($maUnit1->name)->toBe('MA Unit 1')
        ->and($maUnit1->category)->toBe(OrganizationCategory::Formal)
        ->and($maUnit1->jenjang)->toBe(Jenjang::Ma);
});

test('seeder tenant pertama aman dijalankan dua kali', function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(FirstTenantSeeder::class);

    expect(Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->count())->toBe(1)
        ->and(Organization::query()->count())->toBe(23);
});
