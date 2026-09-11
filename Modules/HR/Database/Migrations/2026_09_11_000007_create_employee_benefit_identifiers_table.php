<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-006 §7.7 — Employee Benefit Identifier (mis. nomor peserta
 * BPJS).
 *
 * Struktur kolom mencerminkan persis
 * `recruitment_candidate_identifiers` (HR-003 §7.5) — memakai ULANG
 * `PersonIdentifierCipherInterface` milik Core (AES encryption +
 * HMAC-SHA256 fingerprint satu arah) lewat
 * EloquentEmployeeBenefitIdentifierRepository, TIDAK membangun
 * primitif kriptografi baru. Tetap HR-owned (bukan Core
 * `person_identifiers`) karena identifier BPJS/benefit bukan
 * identitas legal kanonik seorang Person — murni fakta domain benefit.
 *
 * `benefit_program_id` REDUNDAN TERHADAP `employee_benefit_
 * participation_id` secara sengaja (bukan normalisasi ceroboh) — PRD
 * §7.7 menyebutnya eksplisit sebagai "integrity/indexing aid, bukan
 * sumber ownership kedua". Kolom ini memungkinkan FK KOMPOSIT
 * 3-kolom ke `employee_benefit_participations(id, benefit_program_id,
 * tenant_id)` (lihat migration sebelumnya) — menegakkan
 * "benefit_program_id harus sama dengan program milik participation
 * yang direferensikan" sebagai jaminan DB SUNGGUHAN, bukan cuma
 * validasi aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_benefit_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_benefit_participation_id');
            $table->uuid('benefit_program_id');
            $table->string('identifier_type', 50);

            // Ciphertext (Crypt::encryptString via PersonIdentifierCipher)
            // — TEXT karena ciphertext lebih panjang dari nilai asli.
            $table->text('encrypted_value');

            // Hex HMAC-SHA256 = 64 karakter — dipakai untuk duplicate
            // detection tanpa pernah menyimpan/membaca raw identifier.
            $table->char('value_fingerprint', 64);

            $table->string('issuer', 150)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            // §7.7 "Recommended uniqueness" — satu identifier
            // (program + tipe + nilai) hanya boleh terdaftar SATU
            // kali dalam tenant yang sama, terlepas dari participation
            // mana yang memilikinya.
            $table->unique(
                ['tenant_id', 'benefit_program_id', 'identifier_type', 'value_fingerprint'],
                'uq_benefit_identifiers_identity',
            );

            $table->index(
                'employee_benefit_participation_id',
                'idx_benefit_identifiers_participation',
            );

            // FK KOMPOSIT 3-KOLOM — lihat catatan arsitektur kelas di
            // atas. Ini yang benar-benar menegakkan invariant
            // "benefit_program_id konsisten dengan participation yang
            // direferensikan", bukan cuma FK 2-kolom biasa.
            $table->foreign(
                ['employee_benefit_participation_id', 'benefit_program_id', 'tenant_id'],
                'fk_benefit_identifiers_participation_program_tenant',
            )
                ->references(['id', 'benefit_program_id', 'tenant_id'])
                ->on('employee_benefit_participations')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE employee_benefit_identifiers
                ADD CONSTRAINT chk_benefit_identifiers_status
                CHECK (status IN ('ACTIVE', 'INACTIVE'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employee_benefit_identifiers
                ADD CONSTRAINT chk_benefit_identifiers_expires_after_issued
                CHECK (expires_at IS NULL OR issued_at IS NULL OR expires_at >= issued_at)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_benefit_identifiers');
    }
};
