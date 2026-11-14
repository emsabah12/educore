<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isian PTK pegawai untuk Simpatika (PRD-001 §21.1, HR-10a). Satu baris per pegawai; semua isian opsional.
 *
 * - mother_name_encrypted: nama ibu kandung terenkripsi (kunci aplikasi), seperti NIK (§4.5).
 * - certified: null = belum diisi, false = belum sertifikasi, true = sudah (tahun sertifikasi wajib).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_ptk_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->unique()->constrained('hr_employees')->cascadeOnDelete();
            $table->text('mother_name_encrypted')->nullable();
            $table->smallInteger('sd_graduation_year')->nullable();
            $table->string('ptk_function', 30)->nullable();
            $table->string('civil_servant', 10)->nullable();
            $table->boolean('certified')->nullable();
            $table->smallInteger('certification_year')->nullable();
            $table->string('educator_certificate_number', 50)->nullable();
            $table->foreignUuid('updated_by_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_function_check CHECK (ptk_function IS NULL OR ptk_function IN ('GURU_KELAS', 'GURU_MAPEL', 'GURU_BK', 'KEPALA', 'TENAGA_ADMINISTRASI', 'PUSTAKAWAN', 'LABORAN', 'LAINNYA'))");
        DB::statement("ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_civil_servant_check CHECK (civil_servant IS NULL OR civil_servant IN ('PNS', 'NON_PNS'))");
        DB::statement('ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_sd_year_check CHECK (sd_graduation_year IS NULL OR sd_graduation_year BETWEEN 1940 AND 2200)');
        DB::statement('ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_certification_check CHECK ((certified IS TRUE AND certification_year IS NOT NULL) OR (certified IS NOT TRUE AND certification_year IS NULL AND educator_certificate_number IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_ptk_profiles');
    }
};
