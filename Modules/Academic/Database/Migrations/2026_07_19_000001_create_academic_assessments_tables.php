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
        /*
         * 1. TABEL PENGATURAN KOMPONEN PENILAIAN
         *
         * Contoh:
         * - TUGAS
         * - UTS
         * - UAS
         * - KOGNITIF
         * - ABSENSI
         */
        Schema::create('assessment_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();

            $table->uuid('academic_period_id')->index();

            $table->uuid('academic_subject_id')->index();

            $table->string('component_name', 100);

            $table->decimal('weight', 5, 2);

            $table->timestamps();

            /*
             * Tenant isolation.
             */
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            /*
             * Mencegah duplikasi komponen penilaian
             * pada tenant, periode akademik, dan mata pelajaran
             * yang sama.
             */
            $table->unique(
                [
                    'tenant_id',
                    'academic_period_id',
                    'academic_subject_id',
                    'component_name',
                ],
                'idx_assessment_settings_unique'
            );
        });

        /*
         * 2. TABEL NILAI SISWA
         */
        Schema::create('student_grades', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
     * Tenant pemilik data nilai.
     */
            $table->uuid('tenant_id')->index();

            /*
     * Komponen penilaian.
     */
            $table->uuid('assessment_setting_id')->index();

            /*
     * Siswa penerima nilai.
     */
            $table->uuid('student_id')->index();

            /*
     * Employee yang bertindak sebagai teacher
     * dan memasukkan nilai dalam context tertentu.
     *
     * teacher_id bukan users.id.
     *
     * Authentication:
     * users
     *
     * Identity:
     * persons
     *
     * Organizational persona:
     * employees
     *
     * Domain actor:
     * employees.id
     */
            $table->uuid('teacher_id')->index();

            /*
     * Nilai mentah dengan rentang 0.00 - 100.00.
     */
            $table->decimal('score', 5, 2);

            /*
     * Catatan tambahan dari guru.
     */
            $table->text('notes')->nullable();

            $table->timestamps();

            /*
     * Tenant isolation.
     */
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            /*
     * Relasi ke komponen penilaian.
     */
            $table->foreign('assessment_setting_id')
                ->references('id')
                ->on('assessment_settings')
                ->cascadeOnDelete();

            /*
     * Relasi ke siswa.
     */
            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();

            /*
     * Relasi ke employee yang bertindak sebagai teacher.
     */
            $table->foreign('teacher_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            /*
     * Satu siswa hanya boleh memiliki
     * satu nilai untuk satu komponen penilaian.
     */
            $table->unique(
                [
                    'assessment_setting_id',
                    'student_id',
                ],
                'idx_student_grades_unique'
            );
        });
    }

    /**
     * Batalkan migrasi tabel penilaian akademik.
     */
    public function down(): void
    {
        /*
         * student_grades harus dihapus terlebih dahulu
         * karena memiliki foreign key ke assessment_settings.
         */
        Schema::dropIfExists('student_grades');

        Schema::dropIfExists('assessment_settings');
    }
};
