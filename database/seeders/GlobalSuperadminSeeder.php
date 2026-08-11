<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;

final class GlobalSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $superadminEmail = 'bsaeful12@gmail.com';
        $personName = 'EduCore Platform Owner';

        DB::transaction(function () use (
            $superadminEmail,
            $personName,
        ): void {
            $user = User::query()
                ->where('email', $superadminEmail)
                ->first();

            if ($user === null) {
                $person = PersonModel::query()->create([
                    'name' => $personName,
                    'given_name' => 'EduCore',
                    'middle_name' => 'Platform',
                    'family_name' => 'Owner',
                    'status' => 'ACTIVE',
                ]);

                $user = new User();
                $user->forceFill([
                    'person_id' => (string) $person->getKey(),
                    'email' => $superadminEmail,
                    'password' => Hash::make('PlatformSecure2026!'),
                    'status' => 'ACTIVE',
                    'is_superadmin' => true,
                    'email_verified_at' => now(),
                ]);
                $user->save();

                $this->command?->info(
                    'New Global Superadmin record generated successfully.',
                );

                return;
            }

            $person = $user->person()->firstOrFail();
            $person->forceFill([
                'name' => $personName,
                'given_name' => 'EduCore',
                'middle_name' => 'Platform',
                'family_name' => 'Owner',
                'status' => 'ACTIVE',
            ]);
            $person->save();

            $user->forceFill([
                'password' => Hash::make('PlatformSecure2026!'),
                'status' => 'ACTIVE',
                'is_superadmin' => true,
                'email_verified_at' => now(),
            ]);
            $user->save();

            $this->command?->comment(
                'Existing Global Superadmin credentials updated successfully.',
            );
        });

        $this->command?->info(
            'Idempotent Global Superadmin seeding operations completed.',
        );
    }
}
