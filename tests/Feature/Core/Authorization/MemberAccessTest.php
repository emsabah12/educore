<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageMemberAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Authorization\MemberAccessSummary;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Authorization\Exceptions\AccessChangeException;
use Modules\Core\Domain\Authorization\Exceptions\AccessException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Database\Seeders\HrCatalogSeeder;
use Modules\HR\Domain\Catalog\CatalogKind;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Penugasan & role tingkat Yayasan oleh Admin Yayasan (PRD-000 §4.7 F5b).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->baru = penugasanAnggotaBaru($this->tenant, 'Rahmat Hidayat');
});

function penugasanAnggotaBaru(Tenant $tenant, string $name): Membership
{
    $person = Person::query()->create(['name' => $name]);

    return app(GrantMembership::class)->handle($person->id, $tenant->id);
}

function penugasanMembership(User $user, Tenant $tenant): Membership
{
    return Membership::query()->where('person_id', $user->person_id)->where('tenant_id', $tenant->id)->sole();
}

/**
 * @return list<string>
 */
function penugasanRoleYayasan(Membership $membership): array
{
    return DB::table('membership_roles')
        ->join('roles', 'roles.id', '=', 'membership_roles.role_id')
        ->where('membership_roles.membership_id', $membership->id)
        ->orderBy('roles.key')
        ->pluck('roles.key')
        ->all();
}

/**
 * @return list<string>
 */
function penugasanRole(OrganizationalAssignment $assignment): array
{
    return DB::table('organizational_assignment_roles')
        ->join('roles', 'roles.id', '=', 'organizational_assignment_roles.role_id')
        ->where('organizational_assignment_roles.organizational_assignment_id', $assignment->id)
        ->orderBy('roles.key')
        ->pluck('roles.key')
        ->all();
}

/**
 * @return list<OrganizationalAssignment>
 */
function penugasanMilik(Membership $membership): array
{
    return array_values(OrganizationalAssignment::query()
        ->withoutGlobalScope('tenant')
        ->where('membership_id', $membership->id)
        ->orderBy('created_at')
        ->get()
        ->all());
}

function penugasanJumlahAudit(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

// ── Katalog pilihan role ──────────────────────────────────────────────────────

test('katalog menawarkan role tingkat Yayasan dan role penugasan sesuai PRD', function () {
    $catalog = app(AccessCatalog::class);

    expect(array_keys($catalog->tenantRoleOptions()))->toBe([
        CoreAccess::ROLE_ADMIN_YAYASAN,
        CoreAccess::ROLE_PIMPINAN,
        HrAccess::ROLE_ADMIN_SDM,
    ])->and(array_keys($catalog->assignmentRoleOptions()))->toBe([
        CoreAccess::ROLE_PIMPINAN,
        CoreAccess::ROLE_KEPALA_LEMBAGA,
        CoreAccess::ROLE_KOORDINATOR,
        CoreAccess::ROLE_STAF,
        HrAccess::ROLE_PETUGAS_PRESENSI,
        AcademicAccess::ROLE_OPERATOR_AKADEMIK,
    ]);

    expect(fn () => (new AccessCatalog)->offerAsTenantRole('tidak-ada'))->toThrow(AccessException::class);
});

// ── Role tingkat Yayasan ──────────────────────────────────────────────────────

test('admin yayasan memasang role Admin SDM tingkat Yayasan; memasang dua kali ditolak', function () {
    $before = penugasanJumlahAudit(AuditAction::ROLE_GRANTED);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', $this->baru->id), ['role' => HrAccess::ROLE_ADMIN_SDM])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(penugasanRoleYayasan($this->baru))->toBe([HrAccess::ROLE_ADMIN_SDM])
        ->and(penugasanJumlahAudit(AuditAction::ROLE_GRANTED))->toBe($before + 1);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', $this->baru->id), ['role' => HrAccess::ROLE_ADMIN_SDM])
        ->assertSessionHasErrors(['role' => 'Role ini sudah terpasang.']);
});

test('role yang tidak ditawarkan sebagai role tingkat Yayasan ditolak', function (string $role) {
    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', $this->baru->id), ['role' => $role])
        ->assertSessionHasErrors(['role' => 'Pilih role dari daftar yang tersedia.']);

    expect(penugasanRoleYayasan($this->baru))->toBe([]);
})->with([CoreAccess::ROLE_KEPALA_LEMBAGA, CoreAccess::ROLE_STAF, 'tidak-ada', '']);

test('mencabut role tingkat Yayasan tercatat; role yang tidak terpasang ditolak', function () {
    app(ManageRoles::class)->grantTenantRole($this->baru, CoreAccess::ROLE_PIMPINAN);
    $before = penugasanJumlahAudit(AuditAction::ROLE_REVOKED);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.revoke', $this->baru->id), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertSessionHasNoErrors();

    expect(penugasanRoleYayasan($this->baru))->toBe([])
        ->and(penugasanJumlahAudit(AuditAction::ROLE_REVOKED))->toBe($before + 1);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.revoke', $this->baru->id), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertSessionHasErrors(['role' => 'Role ini tidak terpasang.']);
});

test('admin tidak bisa mencabut role Admin Yayasan miliknya sendiri, tetapi boleh milik admin lain', function () {
    $adminMembership = penugasanMembership($this->admin, $this->tenant);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.revoke', $adminMembership->id), ['role' => CoreAccess::ROLE_ADMIN_YAYASAN])
        ->assertSessionHasErrors(['role' => 'Anda tidak bisa mencabut role Admin Yayasan milik Anda sendiri.']);

    expect(penugasanRoleYayasan($adminMembership))->toContain(CoreAccess::ROLE_ADMIN_YAYASAN);

    $adminKedua = penugasanMembership(DevAccounts::user('kepala.smk'), $this->tenant);
    app(ManageRoles::class)->grantTenantRole($adminKedua, CoreAccess::ROLE_ADMIN_YAYASAN);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.revoke', $adminKedua->id), ['role' => CoreAccess::ROLE_ADMIN_YAYASAN])
        ->assertSessionHasNoErrors();

    expect(penugasanRoleYayasan($adminKedua))->not->toContain(CoreAccess::ROLE_ADMIN_YAYASAN);
});

test('role Admin Yayasan terakhir di yayasan tidak bisa dicabut', function () {
    $adminMembership = penugasanMembership($this->admin, $this->tenant);

    expect(fn () => app(ManageMemberAccess::class)->revokeTenantRole($this->tenant->id, $adminMembership->id, CoreAccess::ROLE_ADMIN_YAYASAN, null))
        ->toThrow(AccessChangeException::class, 'Ini Admin Yayasan terakhir. Pasang role Admin Yayasan ke orang lain dulu.');

    expect(penugasanRoleYayasan($adminMembership))->toContain(CoreAccess::ROLE_ADMIN_YAYASAN);
});

// ── Penugasan ─────────────────────────────────────────────────────────────────

test('menambah penugasan struktural dengan satu role, lalu penugasan yang sama ditolak', function () {
    $data = ['kind' => 'STRUKTURAL', 'organization_id' => CoreFixtures::node($this->tenant, 'U1-MA')->id, 'role' => CoreAccess::ROLE_STAF];
    $createdBefore = penugasanJumlahAudit(AuditAction::ASSIGNMENT_CREATED);

    $this->actingAs($this->admin)
        ->post(route('access.assignments.store', $this->baru->id), $data)
        ->assertSessionHasNoErrors();

    $penugasan = penugasanMilik($this->baru);

    expect($penugasan)->toHaveCount(1)
        ->and($penugasan[0]->organization_id)->toBe($data['organization_id'])
        ->and($penugasan[0]->jenjang_filter)->toBeNull()
        ->and($penugasan[0]->status)->toBe(AssignmentStatus::Active)
        ->and(penugasanRole($penugasan[0]))->toBe([CoreAccess::ROLE_STAF])
        ->and(penugasanJumlahAudit(AuditAction::ASSIGNMENT_CREATED))->toBe($createdBefore + 1);

    $this->actingAs($this->admin)
        ->post(route('access.assignments.store', $this->baru->id), [...$data, 'role' => CoreAccess::ROLE_KEPALA_LEMBAGA])
        ->assertSessionHasErrors(['form' => 'Penugasan yang sama sudah ada.']);
});

test('menambah penugasan fungsional tingkat Yayasan: Koordinator semua MDA', function () {
    $this->actingAs($this->admin)
        ->post(route('access.assignments.store', $this->baru->id), ['kind' => 'FUNGSIONAL', 'jenjang' => 'MDA', 'role' => CoreAccess::ROLE_KOORDINATOR])
        ->assertSessionHasNoErrors();

    $penugasan = penugasanMilik($this->baru);

    expect($penugasan)->toHaveCount(1)
        ->and($penugasan[0]->organization_id)->toBeNull()
        ->and($penugasan[0]->jenjang_filter)->toBe(Jenjang::Mda)
        ->and(penugasanRole($penugasan[0]))->toBe([CoreAccess::ROLE_KOORDINATOR]);
});

test('isian penugasan yang salah ditolak dengan pesan di field terkait', function () {
    $store = fn (array $data) => $this->actingAs($this->admin)->post(route('access.assignments.store', $this->baru->id), $data);
    $ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
    $lembagaLain = CoreFixtures::unit(CoreFixtures::tenant('YYS-B'), 'B-UNIT1')->id;

    $store(['role' => CoreAccess::ROLE_STAF])->assertSessionHasErrors('kind');
    $store(['kind' => 'STRUKTURAL', 'role' => CoreAccess::ROLE_STAF])->assertSessionHasErrors('organization_id');
    $store(['kind' => 'FUNGSIONAL', 'jenjang' => 'SD', 'role' => CoreAccess::ROLE_STAF])->assertSessionHasErrors('jenjang');
    $store(['kind' => 'STRUKTURAL', 'organization_id' => $ma, 'role' => CoreAccess::ROLE_ADMIN_YAYASAN])->assertSessionHasErrors('role');
    $store(['kind' => 'STRUKTURAL', 'organization_id' => $ma])->assertSessionHasErrors('role');
    $store(['kind' => 'STRUKTURAL', 'organization_id' => $lembagaLain, 'role' => CoreAccess::ROLE_STAF])->assertSessionHasErrors('organization_id');

    expect(penugasanMilik($this->baru))->toBe([]);
});

test('mencabut penugasan melepas role-nya dan langsung berlaku di request berikutnya', function () {
    $kepala = DevAccounts::user('kepala.smk');
    $membership = penugasanMembership($kepala, $this->tenant);
    $penugasan = penugasanMilik($membership)[0];

    $this->actingAs($kepala)->get(route('hr.employees.index'))->assertOk();

    $this->actingAs($this->admin)
        ->post(route('access.assignments.revoke', [$membership->id, $penugasan->id]))
        ->assertSessionHasNoErrors();

    $penugasan->refresh();
    $audit = DB::table('audit_logs')->where('action', AuditAction::ASSIGNMENT_REVOKED)->sole();

    expect($penugasan->status)->toBe(AssignmentStatus::Inactive)
        ->and(penugasanRole($penugasan))->toBe([])
        ->and($audit->subject_id)->toBe($penugasan->id)
        ->and(json_decode((string) $audit->changes, true)['roles'])->toBe([CoreAccess::ROLE_KEPALA_LEMBAGA]);

    expect($this->actingAs($kepala)->get(route('hr.employees.index'))->status())->not->toBe(200);

    $this->actingAs($this->admin)
        ->post(route('access.assignments.revoke', [$membership->id, $penugasan->id]))
        ->assertSessionHasErrors(['form' => 'Penugasan ini sudah dicabut.']);
});

test('penugasan yang pernah dicabut diaktifkan kembali dengan role baru saat ditambah lagi', function () {
    $membership = penugasanMembership(DevAccounts::user('kepala.smk'), $this->tenant);
    $penugasan = penugasanMilik($membership)[0];

    $this->actingAs($this->admin)->post(route('access.assignments.revoke', [$membership->id, $penugasan->id]));
    $this->actingAs($this->admin)
        ->post(route('access.assignments.store', $membership->id), ['kind' => 'STRUKTURAL', 'organization_id' => $penugasan->organization_id, 'role' => CoreAccess::ROLE_STAF])
        ->assertSessionHasNoErrors();

    $semua = penugasanMilik($membership);
    // Penugasan ini punya dua catatan "dibuat" (seeder + diaktifkan kembali). Waktunya bisa sama
    // persis dalam satu detik, jadi dicari berdasarkan isinya, bukan urutan waktu.
    $diaktifkanKembali = DB::table('audit_logs')
        ->where('action', AuditAction::ASSIGNMENT_CREATED)
        ->where('subject_id', $penugasan->id)
        ->get()
        ->filter(fn (object $row): bool => (json_decode((string) $row->changes, true)['diaktifkan_kembali'] ?? false) === true);

    expect($semua)->toHaveCount(1)
        ->and($semua[0]->id)->toBe($penugasan->id)
        ->and($semua[0]->status)->toBe(AssignmentStatus::Active)
        ->and(penugasanRole($semua[0]))->toBe([CoreAccess::ROLE_STAF])
        ->and($diaktifkanKembali)->toHaveCount(1);
});

test('anggota dengan keanggotaan nonaktif tidak bisa diberi role atau penugasan', function () {
    DB::table('memberships')->where('id', $this->baru->id)->update(['status' => 'INACTIVE']);

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', $this->baru->id), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertSessionHasErrors('form');
    $this->actingAs($this->admin)
        ->post(route('access.assignments.store', $this->baru->id), ['kind' => 'FUNGSIONAL', 'jenjang' => 'MDA', 'role' => CoreAccess::ROLE_KOORDINATOR])
        ->assertSessionHasErrors('form');
});

test('role tingkat Yayasan + penugasan: halaman tingkat Yayasan hanya terbuka dari lembaga kerja Seluruh Yayasan', function () {
    $this->seed(HrCatalogSeeder::class);
    $kepala = DevAccounts::user('kepala.smk');
    app(ManageRoles::class)->grantTenantRole(penugasanMembership($kepala, $this->tenant), HrAccess::ROLE_ADMIN_SDM);

    // Lembaga kerja SMK: data pegawai SMK tetap terbuka, tetapi Katalog SDM (tingkat Yayasan) tidak.
    DevAccounts::chooseWorkspace($this, $kepala, 'U3-SMK');

    $this->actingAs($kepala)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('context.workspace.type', 'organization'));
    $this->actingAs($kepala)->get(route('hr.employees.index'))->assertOk();
    $this->actingAs($kepala)->get(route('hr.catalog.index'))->assertForbidden();

    // Pindah ke Seluruh Yayasan: halaman Admin SDM terbuka.
    $this->actingAs($kepala)
        ->post(route('context.workspace.update'), ['assignment_id' => 'tenant'])
        ->assertRedirect(route('dashboard'));
    $this->actingAs($kepala)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('context.workspace.type', 'tenant'));
    $this->actingAs($kepala)->get(route('hr.catalog.index'))->assertOk();
});

// ── Hak akses & cakupan ───────────────────────────────────────────────────────

test('selain admin yayasan ditolak 403', function (string $username) {
    $kepala = penugasanMembership(DevAccounts::user('kepala.smk'), $this->tenant);
    $penugasan = penugasanMilik($kepala)[0];

    $this->actingAs(DevAccounts::user($username))
        ->post(route('access.tenant-roles.store', $this->baru->id), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertForbidden();
    $this->actingAs(DevAccounts::user($username))
        ->post(route('access.assignments.store', $this->baru->id), ['kind' => 'FUNGSIONAL', 'jenjang' => 'MDA', 'role' => CoreAccess::ROLE_KOORDINATOR])
        ->assertForbidden();
    $this->actingAs(DevAccounts::user($username))
        ->post(route('access.assignments.revoke', [$kepala->id, $penugasan->id]))
        ->assertForbidden();

    expect(penugasanMilik($this->baru))->toBe([])
        ->and($penugasan->fresh()?->status)->toBe(AssignmentStatus::Active);
})->with(['kepala.unit1', 'pimpinan.ponpes']);

test('anggota yayasan lain, ID asal-asalan, atau penugasan milik orang lain dijawab 404', function () {
    $lain = penugasanAnggotaBaru(CoreFixtures::tenant('YYS-B'), 'Orang Lain');
    $penugasanKepala = penugasanMilik(penugasanMembership(DevAccounts::user('kepala.smk'), $this->tenant))[0];

    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', $lain->id), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post(route('access.tenant-roles.store', (string) Str::uuid7()), ['role' => CoreAccess::ROLE_PIMPINAN])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post(route('access.assignments.revoke', [$this->baru->id, $penugasanKepala->id]))
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->post(route('access.assignments.revoke', [$this->baru->id, (string) Str::uuid7()]))
        ->assertNotFound();

    expect($penugasanKepala->fresh()?->status)->toBe(AssignmentStatus::Active);
});

// ── Data untuk halaman ────────────────────────────────────────────────────────

test('ringkasan akses memuat role tingkat Yayasan, penugasan aktif, dan pilihan form', function () {
    $adminMembership = penugasanMembership($this->admin, $this->tenant);
    $koordinator = penugasanMembership(DevAccounts::user('koordinator.mda'), $this->tenant);

    $summary = app(MemberAccessSummary::class)->forMembership($this->tenant->id, $koordinator->id, $adminMembership->id);

    expect($summary)->not->toBeNull()
        ->and($summary['membershipActive'])->toBeTrue()
        ->and(array_column($summary['tenantRoles'], 'key'))->toBe([CoreAccess::ROLE_ADMIN_YAYASAN, CoreAccess::ROLE_PIMPINAN, HrAccess::ROLE_ADMIN_SDM])
        ->and(array_column($summary['tenantRoles'], 'granted'))->toBe([false, false, false])
        ->and(array_column($summary['assignments'], 'label'))->toBe(['Biro Pendidikan dan Koordinator Antar Lembaga', 'Semua MDA (Seluruh Yayasan)'])
        ->and(array_column($summary['assignments'], 'functional'))->toBe([false, true])
        ->and($summary['assignments'][1]['roles'])->toBe(['Koordinator'])
        ->and(array_column($summary['options']['assignmentRoles'], 'value'))->toBe([CoreAccess::ROLE_PIMPINAN, CoreAccess::ROLE_KEPALA_LEMBAGA, CoreAccess::ROLE_KOORDINATOR, CoreAccess::ROLE_STAF, HrAccess::ROLE_PETUGAS_PRESENSI, AcademicAccess::ROLE_OPERATOR_AKADEMIK]);

    $diriSendiri = app(MemberAccessSummary::class)->forMembership($this->tenant->id, $adminMembership->id, $adminMembership->id);

    expect($diriSendiri['tenantRoles'][0]['granted'] ?? null)->toBeTrue()
        ->and($diriSendiri['tenantRoles'][0]['lockedReason'] ?? null)->toBe('Role Admin Yayasan milik Anda sendiri tidak bisa dicabut.');
});

test('detail pegawai mengirim data penugasan & role hanya untuk admin yayasan', function () {
    $this->seed(HrCatalogSeeder::class);

    $katalog = fn (CatalogKind $kind, string $code): string => (string) $kind->query()
        ->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');

    $employee = app(CreateEmployee::class)->handle(new NewEmployeeData(
        tenantId: $this->tenant->id,
        membershipId: $this->baru->id,
        name: null,
        gender: Gender::Male,
        birthPlace: null,
        birthDate: null,
        phone: null,
        address: null,
        employeeNumber: null,
        nuptk: null,
        educationLevel: null,
        educationMajor: null,
        employmentTypeId: $katalog(CatalogKind::EmploymentType, 'TETAP'),
        classificationId: null,
        startDate: '2020-07-01',
        organizationId: CoreFixtures::node($this->tenant, 'U1-MTS')->id,
        positionId: $katalog(CatalogKind::Position, 'GURU'),
    ));

    $this->actingAs($this->admin)
        ->get(route('hr.employees.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.member.membershipActive', true)
            ->has('access.member.tenantRoles', 3)
            ->where('access.member.assignments', [])
            ->has('access.member.options.organizations')
            ->has('access.member.options.jenjang'));

    $this->actingAs(DevAccounts::user('kepala.unit1'))
        ->get(route('hr.employees.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('access', null));
});
