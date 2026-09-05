<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.4 — "Tenant-scoped applicant identity/profile before
 * canonical workforce conversion."
 *
 * INV-REC-001 (LOCKED) — "Candidate does not imply Person": kolom
 * `person_id` di sini NULLABLE dan HANYA diisi lewat hiring conversion
 * (Fase E, belum dibangun). Selama kandidat masih dalam proses seleksi,
 * baris ini TIDAK PERNAH menyentuh tabel `persons`/`memberships` milik
 * Core — dua dunia ini sengaja dipisah sampai keputusan hiring resmi
 * diambil.
 *
 * `normalized_email`/`normalized_phone` HANYA petunjuk pencarian/dedup
 * (§7.4: "not a human unique key") — TIDAK ADA unique constraint pada
 * kolom ini. Constraint identitas yang sesungguhnya (exact match) ada
 * di recruitment_candidate_identifiers (§7.5, INV-REC-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('person_id')->nullable();
            $table->string('display_name', 255);
            $table->date('birth_date')->nullable();
            $table->string('primary_email', 320)->nullable();
            $table->string('normalized_email', 320)->nullable();
            $table->string('primary_phone', 32)->nullable();
            $table->string('normalized_phone', 32)->nullable();
            $table->string('source', 50)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            // Supporting key untuk composite FK dari
            // recruitment_candidate_identifiers &
            // recruitment_applications.
            $table->unique(
                ['id', 'tenant_id'],
                'uq_recruitment_candidates_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_recruitment_candidates_tenant_status',
            );
            // Dedup/search hint index — BUKAN unique (§7.4: normalized
            // email/phone "not a human unique key").
            $table->index(
                ['tenant_id', 'normalized_email'],
                'idx_recruitment_candidates_normalized_email',
            );
            $table->index(
                ['tenant_id', 'normalized_phone'],
                'idx_recruitment_candidates_normalized_phone',
            );

            // Simple FK (bukan composite tenant-safe) — Person adalah
            // identitas Core yang tidak dimiliki satu tenant tertentu,
            // mengikuti pola yang sama seperti employees.membership_id.
            $table->foreign('person_id', 'fk_recruitment_candidates_person')
                ->references('id')
                ->on('persons')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_candidates
                ADD CONSTRAINT chk_recruitment_candidates_status
                CHECK (status IN ('ACTIVE', 'ARCHIVED'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidates');
    }
};
