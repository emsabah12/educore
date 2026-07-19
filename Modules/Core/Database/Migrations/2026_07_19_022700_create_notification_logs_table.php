<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan tabel log audit notifikasi.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Konteks Keamanan Multi-Tenancy
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index()->comment('Penerima jika terdaftar sebagai user internal');

            // Properti Pengiriman
            $table->string('recipient', 150)->index()->comment('Nomor WA, Email, atau Token Perangkat');
            $table->string('channel', 30)->index()->comment('DATABASE, WHATSAPP, EMAIL');
            $table->string('title', 200)->nullable();
            $table->text('body');

            // State Tracking & Telemetry
            $table->string('status', 20)->default('PENDING')->index()->comment('PENDING, SENT, FAILED');
            $table->text('failure_reason')->nullable();

            $table->jsonb('metadata')->nullable()->comment('Menyimpan data detail respons dari API provider third-party');

            $table->timestamps();

            // Foreign Key Restraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Membatalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
