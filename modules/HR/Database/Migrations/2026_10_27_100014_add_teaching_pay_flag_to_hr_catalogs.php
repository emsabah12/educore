<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penerima honor per JP (PRD-001 §16.2, HR-5b): centang di katalog Jenis pegawai & Status kepegawaian.
 * Pegawai menerima honor JP bila jenis pegawai ATAU status kepegawaian di periode berjalannya dicentang.
 *
 * Data yang sudah ada [ASUMSI, OWNER 2026-10-25: penerima = GTT/honorer]: kode HONORER (jenis pegawai) dan
 * GTT (status kepegawaian) langsung dicentang; Admin SDM bisa mengubahnya di Katalog SDM.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_employment_types', 'hr_employment_classifications'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('receives_teaching_pay')->default(false);
            });
        }

        DB::table('hr_employment_types')->where('code', 'HONORER')->update(['receives_teaching_pay' => true]);
        DB::table('hr_employment_classifications')->where('code', 'GTT')->update(['receives_teaching_pay' => true]);
    }

    public function down(): void
    {
        foreach (['hr_employment_types', 'hr_employment_classifications'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('receives_teaching_pay');
            });
        }
    }
};
