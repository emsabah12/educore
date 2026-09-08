<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §PRD Subscription & Custom Role — `tenant_id` NULL = role
 * sistem/global (mis. `admin`), terisi = role kustom milik SATU
 * tenant.
 *
 * PENTING (PostgreSQL): unique constraint biasa pada `(tenant_id,
 * name)` TIDAK cukup — PostgreSQL menganggap setiap NULL berbeda satu
 * sama lain dalam pemeriksaan uniqueness, jadi dua role global bisa
 * saja punya nama sama tanpa terdeteksi. Solusinya DUA partial unique
 * index terpisah:
 * - `name` unik di antara role GLOBAL saja (tenant_id IS NULL)
 * - `(tenant_id, name)` unik di antara role KUSTOM saja (tenant_id
 *   IS NOT NULL) — dua tenant BOLEH punya role kustom bernama sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->uuid('tenant_id')
                ->nullable()
                ->after('id');

            $table->foreign('tenant_id', 'fk_roles_tenant')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_unique');

        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_roles_global_name
                ON roles (name)
                WHERE tenant_id IS NULL
                SQL,
        );

        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_roles_tenant_name
                ON roles (tenant_id, name)
                WHERE tenant_id IS NOT NULL
                SQL,
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_roles_tenant_name');
        DB::statement('DROP INDEX IF EXISTS uq_roles_global_name');

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropForeign('fk_roles_tenant');
            $table->dropColumn('tenant_id');
        });

        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_name_unique UNIQUE (name)');
    }
};
