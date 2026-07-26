<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi tabel auth_sessions.
     */
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            // Menggunakan UUID v7 native untuk Primary Key
            $table->uuid('id')->primary();

            // Relasi asing ke Global Identity System (users) dan Tenant Scoping
            $table->uuid('user_id')->index();
            $table->uuid('tenant_id')->index(); // Menyelaraskan dengan penamaan master tenants

            // Atribut Token Otentikasi & Keamanan Session
            $table->string('token_hash', 64)->unique()->comment('SHA-256 hash dari token transaksional');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Lifecycle Session Management
            $table->timestamp('payload_expires_at')->index();
            $table->timestamps();

            // Aturan Integritas Data (Cascading Guard)
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
