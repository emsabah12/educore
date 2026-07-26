<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menjalankan pembuatan struktur tabel Contextual RBAC secara independen tanpa dependensi model.
     */
    public function up(): void
    {
        // 1. Tabel Master Roles (Global Lookup)
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name')->unique(); // Contoh: 'admin', 'teacher', 'student'
                $table->string('display_name');  // Contoh: 'Administrator Sekolah'
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        // 2. Tabel Master Permissions (Global Lookup)
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name')->unique(); // Contoh: 'academic:create-class', 'finance:collect-spp'
                $table->string('display_name');
                $table->timestamps();
            });
        }

        // 3. Tabel Pivot Many-to-Many: Role <-> Permission
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->uuid('role_id');
                $table->uuid('permission_id');

                // Relasi asing dengan tipe data UUID yang konsisten
                $table->foreign('role_id')
                    ->references('id')
                    ->on('roles')
                    ->onDelete('cascade');

                $table->foreign('permission_id')
                    ->references('id')
                    ->on('permissions')
                    ->onDelete('cascade');

                $table->primary(['role_id', 'permission_id']);
            });
        }

        // 4. Tabel Pivot Utama: Menghubungkan Role ke baris Contextual Membership
        if (!Schema::hasTable('membership_roles')) {
            Schema::create('membership_roles', function (Blueprint $table) {
                $table->uuid('membership_id');
                $table->uuid('role_id');

                $table->foreign('membership_id')
                    ->references('id')
                    ->on('memberships')
                    ->onDelete('cascade');

                $table->foreign('role_id')
                    ->references('id')
                    ->on('roles')
                    ->onDelete('cascade');

                $table->primary(['membership_id', 'role_id']);
            });
        }
    }

    /**
     * Mengembalikan kondisi database jika terjadi rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
