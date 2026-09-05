<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.5 — "Securely retain strong applicant identity claims used
 * for duplicate detection and canonical Person resolution."
 *
 * Struktur kolom ini SENGAJA mencerminkan persis `person_identifiers`
 * milik Core (Modules/Core/Person) — dokumen menyebutnya eksplisit
 * sebagai "Core-compatible normalization/fingerprint contract". Kita
 * memakai ULANG `PersonIdentifierCipherInterface` yang sudah ada
 * (AES encryption + HMAC-SHA256 fingerprint satu arah) lewat
 * RecruitmentCandidateIdentifierRepository — TIDAK membangun primitif
 * kriptografi baru.
 *
 * INV-REC-003 (LOCKED) — "Legal identifier exactness": constraint unik
 * di bawah ini menegakkan pencocokan EXACT (lewat fingerprint), bukan
 * fuzzy/samar. INV-REC-004 — pencocokan lemah (nama/email/telepon dari
 * §7.4) TIDAK PERNAH memicu auto-resolve ke Person; hanya identifier
 * kuat di tabel inilah yang bisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidate_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('candidate_id');
            $table->string('type', 50);
            $table->char('issuing_country_code', 2);

            // Ciphertext (Crypt::encryptString via PersonIdentifierCipher)
            // — TEXT karena ciphertext lebih panjang dari nilai asli.
            $table->text('encrypted_value');

            // Hex HMAC-SHA256 = 64 karakter — dipakai untuk duplicate
            // detection tanpa pernah menyimpan/membaca raw identifier.
            $table->char('value_fingerprint', 64);

            $table->timestampTz('verified_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            // INV-REC-003: satu identifier kuat (tipe + negara + nilai)
            // hanya boleh dimiliki SATU Candidate master dalam tenant
            // yang sama — mencegah dua baris Candidate untuk orang yang
            // sama, sekalipun Candidate tetap boleh melamar ke banyak
            // Vacancy (lewat recruitment_applications terpisah).
            $table->unique(
                ['tenant_id', 'type', 'issuing_country_code', 'value_fingerprint'],
                'uq_recruitment_candidate_identifiers_identity',
            );

            $table->index(
                'candidate_id',
                'idx_recruitment_candidate_identifiers_candidate',
            );

            $table->foreign(
                ['candidate_id', 'tenant_id'],
                'fk_recruitment_candidate_identifiers_candidate_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_candidates')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_identifiers');
    }
};
