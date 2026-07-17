<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan pembuatan tabel audit_logs.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            // Menggunakan format UUIDv7 sebagai primary key yang berurutan secara waktu (chronologically sortable)
            $table->uuid('id')->primary();

            // Konteks Multi-Tenancy (Bisa NULL jika aktivitas terjadi sebelum user memilih tenant/sekolah)
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();

            // Detail Aktivitas Audit
            $table->string('event_type', 50)->index(); // Contoh: 'auth.login_success', 'auth.failed', 'user.created'
            $table->text('description'); // Penjelasan naratif apa yang terjadi

            // Data Konteks Teknis (Metadata)
            $table->jsonb('payload')->nullable(); // Menyimpan payload request mentah atau rekaman sebelum-sesudah data diubah
            $table->string('ip_address', 45)->nullable(); // Mampu menampung format IPv4 maupun IPv6
            $table->text('user_agent')->nullable(); // Informasi browser/klien perangkat pengirim request

            // Penanda Waktu Kejadian (Hanya membutuhkan created_at karena sifatnya yang immutable)
            $table->timestamp('created_at')->useCurrent()->index();

            // Mendefinisikan Foreign Key Constraints untuk menjaga integritas referensial data
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Membatalkan migrasi (Drop Tabel).
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
