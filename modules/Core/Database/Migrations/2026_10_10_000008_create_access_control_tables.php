<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog role & permission + pemasangan role ke anggota (PRD-000 §5, §7).
 *
 *   permissions                      katalog global, key `modul.resource.aksi`
 *   roles                            katalog global, kumpulan permission
 *   role_permission                  isi setiap role
 *   membership_roles                 role tenant-wide (berlaku di seluruh pohon)
 *   organizational_assignment_roles  role di cakupan sebuah penugasan
 *
 * Katalog diisi dari kode lewat `php artisan educore:sync-access` (OD-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 50)->unique();
            $table->string('name', 200);
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        // Target foreign key gabungan dari organizational_assignment_roles.
        Schema::table('organizational_assignments', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            // Role yang masih dipakai tidak bisa dihapus dari katalog.
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['membership_id', 'role_id'], 'membership_roles_pk');
            $table->index('tenant_id');

            // Membership WAJIB dari yayasan yang sama dengan baris ini.
            $table->foreign(['tenant_id', 'membership_id'], 'membership_roles_membership_fk')
                ->references(['tenant_id', 'id'])
                ->on('memberships')
                ->cascadeOnDelete();
        });

        Schema::create('organizational_assignment_roles', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organizational_assignment_id');
            $table->uuid('role_id');
            $table->timestamps();

            $table->primary(['organizational_assignment_id', 'role_id'], 'assignment_roles_pk');
            $table->index('tenant_id', 'assignment_roles_tenant_index');

            // Nama constraint dibuat pendek: batas nama identifier PostgreSQL 63 karakter.
            $table->foreign('role_id', 'assignment_roles_role_fk')
                ->references('id')
                ->on('roles')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'organizational_assignment_id'], 'assignment_roles_assignment_fk')
                ->references(['tenant_id', 'id'])
                ->on('organizational_assignments')
                ->cascadeOnDelete();
        });

        // Format key dijaga juga di database, bukan hanya di kode.
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_key_format_check CHECK (key ~ '^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$')");
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_key_format_check CHECK (key ~ '^[a-z][a-z0-9-]{1,49}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_assignment_roles');
        Schema::dropIfExists('membership_roles');

        Schema::table('organizational_assignments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
