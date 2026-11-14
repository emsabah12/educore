<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyesuaikan isian dengan formulir A05 Simpatika (PRD-001 §21.3, HR-10c-1):
 *
 * - OD-HR-28: fungsi PTK Guru/Staf + kolom baru `school_position` (jabatan di madrasah/sekolah induk, 18 pilihan).
 *   Data lama: GURU_KELAS/GURU_MAPEL/GURU_BK → GURU; KEPALA → GURU + KEPALA; TENAGA_ADMINISTRASI → STAF + STAF_TU;
 *   PUSTAKAWAN → STAF + PUSTAKAWAN; LABORAN → STAF + LABORAN; LAINNYA → kosong.
 * - OD-HR-29: pendidikan SD, SMP, SMA, SMK, D1, D2, D3, D4, S1, S2, S3, LAINNYA; D4_S1 lama → S1 (pegawai & pelamar).
 */
return new class extends Migration
{
    private const POSITIONS = "'PENGAWAS', 'KEPALA', 'WAKIL_KEPALA', 'KEPALA_LAB', 'LABORAN', 'TEKNISI_LAB', 'KEPALA_TU', 'BENDAHARA', 'STAF_TU', 'KEPALA_PERPUS', 'PUSTAKAWAN', 'PENGEMUDI', 'PENJAGA', 'PESURUH', 'KEBERSIHAN', 'KEBUN', 'KEPALA_PROGRAM', 'KEAHLIAN'";

    public function up(): void
    {
        Schema::table('hr_ptk_profiles', function (Blueprint $table): void {
            $table->string('school_position', 30)->nullable();
        });

        DB::statement('ALTER TABLE hr_ptk_profiles DROP CONSTRAINT hr_ptk_profiles_function_check');
        DB::statement("UPDATE hr_ptk_profiles SET school_position = CASE ptk_function
            WHEN 'KEPALA' THEN 'KEPALA' WHEN 'TENAGA_ADMINISTRASI' THEN 'STAF_TU'
            WHEN 'PUSTAKAWAN' THEN 'PUSTAKAWAN' WHEN 'LABORAN' THEN 'LABORAN' END");
        DB::statement("UPDATE hr_ptk_profiles SET ptk_function = CASE
            WHEN ptk_function IN ('GURU_KELAS', 'GURU_MAPEL', 'GURU_BK', 'KEPALA') THEN 'GURU'
            WHEN ptk_function IN ('TENAGA_ADMINISTRASI', 'PUSTAKAWAN', 'LABORAN') THEN 'STAF' END");
        DB::statement("ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_function_check CHECK (ptk_function IS NULL OR ptk_function IN ('GURU', 'STAF'))");
        DB::statement('ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_school_position_check CHECK (school_position IS NULL OR school_position IN ('.self::POSITIONS.'))');

        DB::statement('ALTER TABLE hr_employees DROP CONSTRAINT hr_employees_education_level_check');
        DB::statement("UPDATE hr_employees SET education_level = 'S1' WHERE education_level = 'D4_S1'");
        DB::statement("UPDATE hr_applicants SET education_level = 'S1' WHERE education_level = 'D4_S1'");
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_education_level_check CHECK (education_level IS NULL OR education_level IN ('SD', 'SMP', 'SMA', 'SMK', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3', 'LAINNYA'))");
    }

    /**
     * Kembali ke isian lama sebisanya (tidak bisa sempurna: Guru → Guru Mapel, Staf → Tenaga Administrasi, SMK → SMA).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE hr_employees DROP CONSTRAINT hr_employees_education_level_check');
        DB::statement("UPDATE hr_employees SET education_level = CASE education_level WHEN 'S1' THEN 'D4_S1' WHEN 'D4' THEN 'D4_S1' WHEN 'SMK' THEN 'SMA' ELSE education_level END");
        DB::statement("UPDATE hr_applicants SET education_level = CASE education_level WHEN 'S1' THEN 'D4_S1' WHEN 'D4' THEN 'D4_S1' WHEN 'SMK' THEN 'SMA' ELSE education_level END");
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_education_level_check CHECK (education_level IS NULL OR education_level IN ('SD', 'SMP', 'SMA', 'D1', 'D2', 'D3', 'D4_S1', 'S2', 'S3', 'LAINNYA'))");

        DB::statement('ALTER TABLE hr_ptk_profiles DROP CONSTRAINT hr_ptk_profiles_school_position_check');
        DB::statement('ALTER TABLE hr_ptk_profiles DROP CONSTRAINT hr_ptk_profiles_function_check');
        DB::statement("UPDATE hr_ptk_profiles SET ptk_function = CASE
            WHEN school_position = 'KEPALA' THEN 'KEPALA'
            WHEN school_position = 'PUSTAKAWAN' THEN 'PUSTAKAWAN'
            WHEN school_position = 'LABORAN' THEN 'LABORAN'
            WHEN ptk_function = 'GURU' THEN 'GURU_MAPEL'
            WHEN ptk_function = 'STAF' THEN 'TENAGA_ADMINISTRASI' END");
        DB::statement("ALTER TABLE hr_ptk_profiles ADD CONSTRAINT hr_ptk_profiles_function_check CHECK (ptk_function IS NULL OR ptk_function IN ('GURU_KELAS', 'GURU_MAPEL', 'GURU_BK', 'KEPALA', 'TENAGA_ADMINISTRASI', 'PUSTAKAWAN', 'LABORAN', 'LAINNYA'))");

        Schema::table('hr_ptk_profiles', function (Blueprint $table): void {
            $table->dropColumn('school_position');
        });
    }
};
