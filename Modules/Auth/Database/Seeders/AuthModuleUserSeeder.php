<?php

declare(strict_types=1);

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Support\Uuid\UuidV7;

final class AuthModuleUserSeeder extends Seeder
{
    /**
     * Seed a development authentication fixture while preserving canonical
     * Person → User and Person → Membership ownership.
     *
     * The downstream employee-profile insert is intentionally left as an
     * existing HR fixture concern; authorization is not sourced from a
     * memberships.role column.
     */
    public function run(): void
    {
        $tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        if (! DB::table('tenants')->where('id', $tenantId)->exists()) {
            $this->command->info(
                "Tenant acuan tidak ditemukan. Membuat Tenant baru: {$tenantId}",
            );

            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => 'Lembaga Pendidikan Pusat EduCore',
                'subdomain' => 'pusat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $userEmail = 'superadmin@educore.id';

        $existingUser = DB::table('users')
            ->where('email', $userEmail)
            ->first();

        if ($existingUser === null) {
            $personId = UuidV7::generate();
            $userId = UuidV7::generate();

            DB::transaction(function () use (
                $personId,
                $userId,
                $userEmail,
            ): void {
                DB::table('persons')->insert([
                    'id' => $personId,
                    'name' => 'Super Administrator Global',
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')->insert([
                    'id' => $userId,
                    'person_id' => $personId,
                    'email' => $userEmail,
                    'password' => Hash::make('secretpassword'),
                    'status' => 'ACTIVE',
                    'is_superadmin' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->command->info(
                "Akun User Global berhasil dibuat. UUID: {$userId}",
            );
        } else {
            $userId = trim((string) $existingUser->id);
            $personId = trim((string) $existingUser->person_id);

            if (
                $userId === ''
                || $personId === ''
            ) {
                throw new \RuntimeException(
                    'Existing authentication fixture has invalid canonical identity linkage.',
                );
            }
        }

        $membership = DB::table('memberships')
            ->where('person_id', $personId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($membership !== null) {
            return;
        }

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info(
            'Jembatan Multi-Tenant Membership berhasil dikaitkan!',
        );
        $this->command->info(
            "-> Membership ID : {$membershipId}",
        );

        /*
         * Existing development-only HR fixture behavior. This does not make
         * Membership an authorization-role source; HR remains a downstream
         * bounded context and is not otherwise refactored in Phase 2G.4.
         */
        if (DB::getSchemaBuilder()->hasTable('pegawais')) {
            DB::table('pegawais')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'nip' => '19982026121001',
                'jabatan' => 'SUPER_ADMINISTRATOR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->command->info(
                '✓ Profil bisnis pegawai development fixture berhasil disemai.',
            );
        }
    }
}
