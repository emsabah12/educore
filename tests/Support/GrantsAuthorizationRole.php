<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shared test helper untuk memberi Role RBAC (ADR-016) ke sebuah
 * Membership di dalam feature test.
 *
 * Dipakai oleh test module manapun yang endpoint-nya dilindungi
 * middleware `tenant.permission:<permission>`, supaya operator test
 * benar-benar punya izin yang dibutuhkan alih-alih hanya token valid.
 */
trait GrantsAuthorizationRole
{
    private function grantRole(
        string $membershipId,
        string $roleName,
    ): void {
        $roleId = DB::table('roles')
            ->where('name', $roleName)
            ->value('id');

        $this->assertIsString(
            $roleId,
            sprintf(
                'Role "%s" tidak ditemukan. Pastikan authorization catalog seeder yang relevan sudah di-seed di setUp().',
                $roleName,
            ),
        );

        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);
    }
}
