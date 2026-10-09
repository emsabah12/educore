<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Membership\AssignToOrganization;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
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
 */
class DevAccountsSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function __construct(
        private readonly GrantMembership $grantMembership,
        private readonly AssignToOrganization $assignToOrganization,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevAccountsSeeder hanya boleh dijalankan di lingkungan local/testing.');
        }

        $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();

        DB::transaction(function () use ($tenant): void {
            // 1. Superadmin platform, tanpa membership → Panel platform.
            $this->account('superadmin@educore.test', 'superadmin', 'Superadmin Platform', Gender::Male, superadmin: true);

            // 2. Admin Yayasan: anggota tanpa penugasan → konteks "Seluruh Yayasan" (OD-09).
            $admin = $this->account('admin.yayasan@educore.test', 'admin.yayasan', 'Admin Yayasan', Gender::Male);
            $this->member($admin, $tenant);

            // 3. Kepala Unit 1: satu penugasan → langsung masuk Unit 1 (OD-08).
            $kepalaUnit = $this->account('kepala.unit1@educore.test', 'kepala.unit1', 'Kepala Unit 1', Gender::Male);
            $this->assign($this->member($kepalaUnit, $tenant), $tenant, 'PONPES-U1');

            // 4. Guru di MA Unit 1 dan MDA Unit 1: dua penugasan → wajib memilih.
            $guru = $this->account('guru@educore.test', 'guru', 'Guru MA & MDA', Gender::Female);
            $guruMembership = $this->member($guru, $tenant);
            $this->assign($guruMembership, $tenant, 'U1-MA');
            $this->assign($guruMembership, $tenant, 'U1-MDA');

            // 5. Koordinator MDA: staf Biro Pendidikan + pembina semua MDA (PRD-000 §4.4).
            $koordinator = $this->account('koordinator.mda@educore.test', 'koordinator.mda', 'Koordinator MDA', Gender::Male);
            $koordinatorMembership = $this->member($koordinator, $tenant);
            $this->assign($koordinatorMembership, $tenant, 'BIRO-PENDIDIKAN');
            $this->assign($koordinatorMembership, $tenant, null, Jenjang::Mda);

            // 6. Pengguna tanpa membership → halaman "Belum terdaftar".
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

    private function assign(Membership $membership, Tenant $tenant, ?string $organizationCode, ?Jenjang $jenjangFilter = null): void
    {
        $organizationId = null;

        if ($organizationCode !== null) {
            $organizationId = Organization::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', $organizationCode)
                ->valueOrFail('id');
        }

        $alreadyAssigned = $membership->assignments()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId)
            ->where('jenjang_filter', $jenjangFilter?->value)
            ->exists();

        if (! $alreadyAssigned) {
            $this->assignToOrganization->handle($membership, $organizationId, $jenjangFilter);
        }
    }
}
