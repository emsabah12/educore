<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periode kepegawaian (PRD-001 §4.3): riwayat jenis & status kepegawaian seorang pegawai.
 *
 * - Maksimal SATU periode terbuka (end_date kosong) per pegawai — dijaga partial unique index.
 * - Periode tidak boleh tumpang tindih — dicek service di dalam transaksi (butuh ekstensi
 *   btree_gist bila mau dijaga database; sengaja tidak dipakai agar instalasi tetap sederhana).
 * - Ganti status (mis. Kontrak → Tetap) = tutup periode lama dengan alasan PERUBAHAN_STATUS
 *   lalu buka periode baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employment_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('employment_type_id');
            $table->uuid('classification_id')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->string('end_note', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
            $table->index(['tenant_id', 'employment_type_id']);
            $table->index(['tenant_id', 'classification_id']);

            // Semua rujukan wajib dari yayasan yang sama.
            $table->foreign(['tenant_id', 'employee_id'])
                ->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'employment_type_id'])
                ->references(['tenant_id', 'id'])->on('hr_employment_types')->restrictOnDelete();
            // classification_id kosong → foreign key tidak diperiksa (MATCH SIMPLE).
            $table->foreign(['tenant_id', 'classification_id'])
                ->references(['tenant_id', 'id'])->on('hr_employment_classifications')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX hr_employment_periods_one_open_unique ON hr_employment_periods (employee_id) WHERE end_date IS NULL');
        DB::statement('ALTER TABLE hr_employment_periods ADD CONSTRAINT hr_employment_periods_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement(<<<'SQL'
            ALTER TABLE hr_employment_periods ADD CONSTRAINT hr_employment_periods_end_reason_check CHECK (
                end_reason IS NULL
                OR end_reason IN ('MENGUNDURKAN_DIRI', 'KONTRAK_SELESAI', 'PENSIUN', 'DIBERHENTIKAN', 'MENINGGAL', 'LAINNYA', 'PERUBAHAN_STATUS')
            )
        SQL);
        // Periode terbuka tidak punya alasan; periode yang ditutup wajib punya alasan.
        DB::statement(<<<'SQL'
            ALTER TABLE hr_employment_periods ADD CONSTRAINT hr_employment_periods_end_consistency_check CHECK (
                (end_date IS NULL AND end_reason IS NULL AND end_note IS NULL)
                OR (end_date IS NOT NULL AND end_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employment_periods');
    }
};
