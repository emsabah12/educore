<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\Tenant;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Data resmi lembaga: NPSN, status, wilayah (PRD-000 §4.6; PRD-001 §21.1, OD-HR-26).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
});

function odtNode(Tenant $tenant, string $code): Organization
{
    return CoreFixtures::node($tenant, $code);
}

test('Admin Yayasan mengisi data resmi lembaga; tampil di halaman; audit tanpa isi', function () {
    $ma = odtNode($this->tenant, 'U1-MA');

    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), [
        'npsn' => ' 20212345 ', 'ownership' => 'SWASTA', 'district' => ' Cibiru ', 'regency' => 'Kota  Bandung', 'province' => 'Jawa Barat',
    ])->assertSessionHasNoErrors();

    $ma->refresh();
    expect([$ma->npsn, $ma->ownership?->value, $ma->district, $ma->regency, $ma->province])
        ->toBe(['20212345', 'SWASTA', 'Cibiru', 'Kota Bandung', 'Jawa Barat']);

    $audit = DB::table('audit_logs')->where('action', AuditAction::ORGANIZATION_DETAILS_UPDATED)->sole();
    expect(json_decode((string) $audit->changes, true))->toBe(['isian' => ['NPSN', 'status', 'kecamatan', 'kab/kota', 'provinsi']])
        ->and((string) $audit->changes)->not->toContain('20212345');

    $this->actingAs($this->admin)->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('options.ownerships', 2)
            ->where('nodes', fn ($nodes) => collect($nodes)->firstWhere('code', 'U1-MA')['details'] === [
                'npsn' => '20212345', 'ownership' => 'SWASTA', 'ownership_label' => 'Swasta',
                'district' => 'Cibiru', 'regency' => 'Kota Bandung', 'province' => 'Jawa Barat',
            ] && collect($nodes)->firstWhere('code', 'PONPES-U1')['details'] === null),
        );

    // Simpan ulang tanpa perubahan tidak menambah audit; mengosongkan diperbolehkan.
    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), [
        'npsn' => '20212345', 'ownership' => 'SWASTA', 'district' => 'Cibiru', 'regency' => 'Kota Bandung', 'province' => 'Jawa Barat',
    ])->assertSessionHasNoErrors();
    expect(DB::table('audit_logs')->where('action', AuditAction::ORGANIZATION_DETAILS_UPDATED)->count())->toBe(1);

    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), ['npsn' => '', 'ownership' => ''])->assertSessionHasNoErrors();
    expect([$ma->refresh()->npsn, $ma->ownership, $ma->province])->toBe([null, null, null]);
});

test('validasi: NPSN 8 angka & unik di yayasan, status dari daftar, hanya node lembaga', function () {
    $ma = odtNode($this->tenant, 'U1-MA');
    $mts = odtNode($this->tenant, 'U1-MTS');
    $unit = odtNode($this->tenant, 'PONPES-U1');

    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), ['npsn' => '1234567'])
        ->assertSessionHasErrors(['npsn' => 'NPSN harus 8 angka.']);
    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), ['ownership' => 'ASING'])
        ->assertSessionHasErrors(['ownership']);
    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), ['province' => str_repeat('a', 101)])
        ->assertSessionHasErrors(['province']);

    $this->actingAs($this->admin)->patch(route('organizations.details', $ma->id), ['npsn' => '20212345'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch(route('organizations.details', $mts->id), ['npsn' => '20212345'])
        ->assertSessionHasErrors(['npsn' => 'NPSN ini sudah dipakai lembaga lain di yayasan ini.']);

    $this->actingAs($this->admin)->patch(route('organizations.details', $unit->id), ['npsn' => '20219999'])
        ->assertSessionHasErrors(['form' => 'Data resmi hanya untuk node berjenis lembaga.']);
});

test('tanpa izin kelola 403; di luar cakupan 404', function () {
    $ma = odtNode($this->tenant, 'U1-MA');
    $u2 = odtNode($this->tenant, 'U2-MA');

    $this->actingAs(DevAccounts::user('kepala.unit1'))->patch(route('organizations.details', $ma->id), ['npsn' => '20212345'])->assertForbidden();
    $this->actingAs(DevAccounts::user('kepala.unit1'))->patch(route('organizations.details', $u2->id), ['npsn' => '20212345'])->assertNotFound();

    expect($ma->refresh()->npsn)->toBeNull();
});
