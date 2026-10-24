<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen & SK pegawai (PRD-001 §15, HR-2a).
 *
 * - `hr_document_types`: katalog jenis dokumen per yayasan, bentuknya sama dengan katalog SDM lain
 *   ditambah `has_expiry` (punya masa berlaku → "berlaku sampai" wajib diisi).
 * - `hr_documents`: satu file per dokumen di disk privat (`storage_path` acak, bukan nama asli).
 *   Tidak dihapus: salah file → dibatalkan (`voided_*`) lalu unggah dokumen baru. File tidak pernah
 *   diganti, sehingga checksum SHA-256 tetap sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE hr_document_types ADD CONSTRAINT hr_document_types_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
        DB::statement("ALTER TABLE hr_document_types ADD CONSTRAINT hr_document_types_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('hr_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('document_type_id');
            $table->string('number', 100)->nullable();
            $table->string('title', 150);
            $table->date('issued_on')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('note', 500)->nullable();

            // File (disk privat). Nama asli hanya untuk tampilan & nama unduhan.
            $table->string('storage_path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('byte_size');
            $table->char('sha256', 64);

            $table->uuid('uploaded_by_membership_id')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->uuid('voided_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique('storage_path');
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'valid_until']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'document_type_id'])->references(['tenant_id', 'id'])->on('hr_document_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'uploaded_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'voided_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_documents ADD CONSTRAINT hr_documents_title_not_blank_check CHECK (btrim(title) <> '')");
        DB::statement('ALTER TABLE hr_documents ADD CONSTRAINT hr_documents_validity_check CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)');
        DB::statement('ALTER TABLE hr_documents ADD CONSTRAINT hr_documents_file_check CHECK (byte_size > 0 AND sha256 ~ \'^[0-9a-f]{64}$\')');
        DB::statement("ALTER TABLE hr_documents ADD CONSTRAINT hr_documents_void_check CHECK ((voided_at IS NULL) = (void_reason IS NULL) AND (void_reason IS NULL OR btrim(void_reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_documents');
        Schema::dropIfExists('hr_document_types');
    }
};
