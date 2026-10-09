<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User = akun digital milik satu Person, tanpa tenant_id (PRD-000 §5, [LAMA] ADR-013).
 *
 * Kolom 2FA disertakan langsung di sini, menggantikan migrasi bawaan starter kit
 * `2025_08_14_170933_add_two_factor_columns_to_users_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $table->string('email')->unique();
            $table->string('username', 50)->nullable()->unique();
            $table->string('password');
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('is_superadmin')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        // Email & username selalu huruf kecil agar login tidak peka huruf besar/kecil
        // dan keunikan tidak bisa diakali dengan variasi kapital.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email = lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_format_check CHECK (username IS NULL OR username ~ '^[a-z0-9._-]{3,50}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
