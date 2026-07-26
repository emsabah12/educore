<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi untuk skema penilaian akademik.
     */
    public function up(): void
    {
        // 1. TABEL PENGATURAN BOBOT KOMPONEN NILAI (Contoh: KOGNITIF, TUGAS, UTS, UAS)
        Schema::create('assessment_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('academic_period_id')->index(); // Terikat ke Tahun Ajaran/Semester aktif
            $table->uuid('academic_subject_id')->index(); // Terikat ke Mapel

            $table->string('component_name', 100); // TUGAS, UTS, UAS, ABSENSI, DLL
            $table->decimal('weight', 5, 2); // Nilai bobot (Persentase), contoh: 20.00 (berarti 20%)

            $table->timestamps();

            // Foreign Key Restrictions
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Mencegah duplikasi nama komponen pada mapel dan semester yang sama di satu sekolah
            $table->unique(['tenant_id', 'academic_period_id', 'academic_subject_id', 'component_name'], 'idx_assessment_settings_unique');
        });

        // 2. TABEL TRANSKRIP NILAI FISIK student
        Schema::create('student_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('assessment_setting_id')->index(); // Merujuk ke komponen bobot di atas
            $table->uuid('student_id')->index(); // Siswa penerima nilai
            $table->uuid('teacher_id')->index(); // Pegawai/Guru penginput nilai (untuk audit trail)

            $table->decimal('score', 5, 2); // Nilai mentah, skala 0.00 sampai 100.00
            $table->text('notes')->nullable(); // Catatan remedial atau pujian guru

            $table->timestamps();

            // Foreign Key Restrictions
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('assessment_setting_id')->references('id')->on('assessment_settings')->onDelete('cascade');

            // Mencegah seorang student mendapatkan dua nilai untuk satu komponen penilaian yang sama
            $table->unique(['assessment_setting_id', 'student_id'], 'idx_student_grades_unique');
        });
    }

    /**
     * Batalkan migrasi tabel jika terjadi rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_grades');
        Schema::dropIfExists('assessment_settings');
    }
};
