<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pendaftaran siswa di lembaga (PRD-002 §4.2): "siswa X terdaftar di Lembaga Y sejak tanggal Z".
 *
 * - Satu siswa boleh terdaftar di beberapa lembaga sekaligus (mis. SMP + MDA), tetapi maksimal
 *   SATU pendaftaran terbuka per lembaga — dijaga partial unique index.
 * - organization_id = node pemilik data untuk penyaringan per pohon lembaga (PRD-002 §5.3).
 *   Wajib lembaga (bukan unit/biro) — dicek service.
 * - Nomor induk siswa unik di lembaga di antara pendaftaran terbuka (database); service juga
 *   menolak nomor induk yang pernah dipakai siswa LAIN di lembaga itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('student_id');
            $table->uuid('organization_id');
            $table->string('student_number', 30)->nullable();
            $table->string('entry_type', 10)->default('BARU');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->string('end_note', 500)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'end_date']);
            $table->index(['tenant_id', 'organization_id', 'end_date']);

            // Target foreign key gabungan dari ac_class_members: kelas & pendaftaran di lembaga yang sama.
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'student_id'])
                ->references(['tenant_id', 'id'])->on('ac_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX ac_enrollments_one_open_unique ON ac_enrollments (student_id, organization_id) WHERE end_date IS NULL');
        DB::statement('CREATE UNIQUE INDEX ac_enrollments_open_number_unique ON ac_enrollments (organization_id, student_number) WHERE end_date IS NULL AND student_number IS NOT NULL');
        // [ASUMSI] Nomor induk siswa: huruf besar, angka, titik, garis miring, tanda "-" (sama dengan nomor induk pegawai).
        DB::statement("ALTER TABLE ac_enrollments ADD CONSTRAINT ac_enrollments_number_format_check CHECK (student_number IS NULL OR student_number ~ '^[A-Z0-9][A-Z0-9./-]{0,29}$')");
        DB::statement("ALTER TABLE ac_enrollments ADD CONSTRAINT ac_enrollments_entry_type_check CHECK (entry_type IN ('BARU', 'PINDAHAN'))");
        DB::statement('ALTER TABLE ac_enrollments ADD CONSTRAINT ac_enrollments_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement(<<<'SQL'
            ALTER TABLE ac_enrollments ADD CONSTRAINT ac_enrollments_end_reason_check CHECK (
                end_reason IS NULL
                OR end_reason IN ('LULUS', 'PINDAH', 'MENGUNDURKAN_DIRI', 'DIKELUARKAN', 'MENINGGAL', 'LAINNYA')
            )
        SQL);
        // Pendaftaran terbuka tidak punya alasan; yang ditutup wajib punya alasan.
        DB::statement(<<<'SQL'
            ALTER TABLE ac_enrollments ADD CONSTRAINT ac_enrollments_end_consistency_check CHECK (
                (end_date IS NULL AND end_reason IS NULL AND end_note IS NULL)
                OR (end_date IS NOT NULL AND end_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_enrollments');
    }
};
