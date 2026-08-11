<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Role;

final class AuthorizationCatalogSeeder extends Seeder
{
    private const ADMIN_ROLE_NAME = 'admin';

    private const ADMIN_DISPLAY_NAME = 'Administrator';

    private const ADMIN_DESCRIPTION = 'Tenant administrator role.';

    /**
     * Ensure the minimal production RBAC catalog exists.
     *
     * Runtime authorization remains database-backed. This seeder only
     * bootstraps canonical baseline definitions and never reconciles or
     * deletes custom roles/permissions.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            Role::query()->updateOrCreate(
                [
                    'name' => self::ADMIN_ROLE_NAME,
                ],
                [
                    'display_name' => self::ADMIN_DISPLAY_NAME,
                    'description' => self::ADMIN_DESCRIPTION,
                ],
            );
        });
    }
}
