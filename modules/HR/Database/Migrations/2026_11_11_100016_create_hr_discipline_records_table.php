<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan disiplin pegawai (PRD-001 §18.1, HR-7a): teguran lisan/tertulis, SP1–SP3, masa berlaku, lampiran
 * surat opsional di disk privat. Tidak dihapus/diubah: salah input → dicabut (`revoked_*`) lalu catat ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_discipline_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->string('kind', 20);
            $table->date('issued_on');
            $table->string('violation', 500);
            $table->string('follow_up', 500)->nullable();
            $table->date('valid_until')->nullable();

            // Lampiran surat (opsional). Nama asli hanya untuk tampilan & nama unduhan.
            $table->string('storage_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->uuid('recorded_by_membership_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 300)->nullable();
            $table->uuid('revoked_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique('storage_path');
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'issued_on']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'recorded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'revoked_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_discipline_records ADD CONSTRAINT hr_discipline_records_kind_check CHECK (kind IN ('TEGURAN_LISAN', 'TEGURAN_TERTULIS', 'SP1', 'SP2', 'SP3'))");
        DB::statement('ALTER TABLE hr_discipline_records ADD CONSTRAINT hr_discipline_records_violation_check CHECK (char_length(btrim(violation)) >= 5)');
        DB::statement("ALTER TABLE hr_discipline_records ADD CONSTRAINT hr_discipline_records_validity_check CHECK ((valid_until IS NULL OR valid_until >= issued_on) AND (kind NOT IN ('SP1', 'SP2', 'SP3') OR valid_until IS NOT NULL))");
        DB::statement("ALTER TABLE hr_discipline_records ADD CONSTRAINT hr_discipline_records_file_check CHECK ((storage_path IS NULL) = (sha256 IS NULL) AND (sha256 IS NULL OR (byte_size > 0 AND sha256 ~ '^[0-9a-f]{64}$')))");
        DB::statement("ALTER TABLE hr_discipline_records ADD CONSTRAINT hr_discipline_records_revoke_check CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL) AND (revoke_reason IS NULL OR btrim(revoke_reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_discipline_records');
    }
};
