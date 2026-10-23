<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor identitas orang (PRD-000 §5, OD-HR-01 revisi 2026-10-23). Saat ini hanya NIK KTP/KK.
 *
 * - encrypted_value  : NIK terenkripsi (kunci aplikasi), tidak bisa dicari langsung.
 * - value_fingerprint: HMAC-SHA256 NIK, untuk mencari & mencegah dobel tanpa membuka isinya.
 *
 * Satu orang maksimal satu NIK; satu NIK hanya milik satu orang di seluruh EduCore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('encrypted_value');
            $table->char('value_fingerprint', 64);
            $table->timestamps();

            $table->unique(['person_id', 'type']);
            $table->unique(['type', 'value_fingerprint']);
        });

        DB::statement("ALTER TABLE person_identifiers ADD CONSTRAINT person_identifiers_type_check CHECK (type IN ('NIK'))");
        DB::statement("ALTER TABLE person_identifiers ADD CONSTRAINT person_identifiers_fingerprint_check CHECK (value_fingerprint ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('person_identifiers');
    }
};
