<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi tabel auth_audits.
     */
    public function up(): void
    {
        Schema::create('auth_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Log bisa mencatat percobaan login gagal (user_id null) tapi tenant_id wajib ada
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();

            // Metadata Audit
            $table->string('event_type', 50)->index()->comment('LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT');
            $table->jsonb('context_payload')->nullable()->comment('Menyimpan IP, UserAgent, dan detail metadata');

            // Ditulis sekali, tidak pernah diubah (Immutable Log)
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_audits');
    }
};
