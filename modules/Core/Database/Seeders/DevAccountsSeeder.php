<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Authorization\SyncAccessCatalog;
use Modules\Core\Application\Membership\AssignToOrganization;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\Tenant;
use RuntimeException;

/**
 * Akun uji per peran untuk development (PRD-000 OD-10). Semua password: "password".
 *
 * HANYA untuk lingkungan local/testing; DatabaseSeeder tidak memanggilnya di production,
 * dan seeder ini juga menolak berjalan sendiri di luar local/testing.
 * Membutuhkan FirstTenantSeeder sudah dijalankan. Aman dijalankan berulang.
 *
 * Akun-akun ini sekaligus contoh skenario uji PRD-000 §7.3.
 */
class DevAccountsSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function __construct(
        private readonly GrantMembership $grantMembership,
        private readonly AssignToOrganization $assignToOrganization,
        private readonly ManageRoles $manageRoles,
        private readonly SyncAccessCatalog $syncAccessCatalog,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevAccountsSeeder hanya boleh dijalankan di lingkungan local/testing.');
        }

        $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();

        DB::transaction(function () use ($tenant): void {
            // Role dibutuhkan di bawah; sinkronisasi aman diulang.
            $this->syncAccessCatalog->handle();

            // 1. Superadmin platform, tanpa membership → Panel platform (tidak menembus data yayasan, OD-12).
            $this->account('superadmin@educore.test', 'superadmin', 'Superadmin Platform', Gender::Male, superadmin: true);

            // 2. Admin Yayasan: role tenant-wide → bekerja di "Seluruh Yayasan".
            $admin = $this->member($this->account('admin.yayasan@educore.test', 'admin.yayasan', 'Admin Yayasan', Gender::Male), $tenant);
            $this->manageRoles->grantTenantRole($admin, CoreAccess::ROLE_ADMIN_YAYASAN);

            // 3. Pimpinan Ponpes: melihat Unit 1–3 dan semua lembaga di dalamnya (§7.3).
            $pimpinan = $this->member($this->account('pimpinan.ponpes@educore.test', 'pimpinan.ponpes', 'Pimpinan Pondok Pesantren', Gender::Male), $tenant);
            $this->assign($pimpinan, $tenant, 'PONPES', CoreAccess::ROLE_PIMPINAN);

            // 4. Kepala Unit 1: satu penugasan → langsung masuk Unit 1 (OD-08).
            $kepalaUnit = $this->member($this->account('kepala.unit1@educore.test', 'kepala.unit1', 'Kepala Unit 1', Gender::Male), $tenant);
            $this->assign($kepalaUnit, $tenant, 'PONPES-U1', CoreAccess::ROLE_KEPALA_LEMBAGA);

            // 5. Kepala SMK Unit 3: hanya SMK Unit 3 (§7.3).
            $kepalaSmk = $this->member($this->account('kepala.smk@educore.test', 'kepala.smk', 'Kepala SMK Unit 3', Gender::Female), $tenant);
            $this->assign($kepalaSmk, $tenant, 'U3-SMK', CoreAccess::ROLE_KEPALA_LEMBAGA);

            // 6. Guru di MA Unit 1 dan MDA Unit 1: dua penugasan → wajib memilih.
            $guru = $this->member($this->account('guru@educore.test', 'guru', 'Guru MA & MDA', Gender::Female), $tenant);
            $this->assign($guru, $tenant, 'U1-MA', CoreAccess::ROLE_STAF);
            $this->assign($guru, $tenant, 'U1-MDA', CoreAccess::ROLE_STAF);

            // 7. Koordinator MDA: staf Biro Pendidikan + pembina semua MDA (PRD-000 §4.4).
            $koordinator = $this->member($this->account('koordinator.mda@educore.test', 'koordinator.mda', 'Koordinator MDA', Gender::Male), $tenant);
            $this->assign($koordinator, $tenant, 'BIRO-PENDIDIKAN', CoreAccess::ROLE_STAF);
            $this->assign($koordinator, $tenant, null, CoreAccess::ROLE_KOORDINATOR, Jenjang::Mda);

            // 8. Pengguna tanpa membership → halaman "Belum terdaftar".
            $this->account('test@example.com', 'penguji', 'Pengguna Uji', Gender::Female);
        });
    }

    private function account(string $email, string $username, string $name, Gender $gender, bool $superadmin = false): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $person = Person::query()->create(['name' => $name, 'gender' => $gender]);

        $user = User::factory()->create([
            'person_id' => $person->id,
            'email' => $email,
            'username' => $username,
        ]);

        if ($superadmin) {
            $user->is_superadmin = true;
            $user->save();
        }

        return $user;
    }

    private function member(User $user, Tenant $tenant): Membership
    {
        return $this->grantMembership->handle($user->person_id, $tenant->id);
    }

    private function assign(Membership $membership, Tenant $tenant, ?string $organizationCode, string $roleKey, ?Jenjang $jenjangFilter = null): void
    {
        $organizationId = null;

        if ($organizationCode !== null) {
            $organizationId = Organization::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', $organizationCode)
                ->valueOrFail('id');
        }

        $assignment = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('membership_id', $membership->id)
            ->where('organization_id', $organizationId)
            ->where('jenjang_filter', $jenjangFilter?->value)
            ->first()
            ?? $this->assignToOrganization->handle($membership, $organizationId, $jenjangFilter);

        $this->manageRoles->grantAssignmentRole($assignment, $roleKey);
    }
}
