<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-9b (PRD-001 §20.2): pelamar yang dijadikan pegawai tertaut ke data pegawainya (sekali saja), dan lowongan
 * menyimpan jumlah pelamar per tahap yang sudah dihapus otomatis (OD-HR-21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_applicants', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable()->after('stage');
            $table->unique('employee_id');
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });

        Schema::table('hr_vacancies', function (Blueprint $table) {
            // Tahap → jumlah pelamar yang sudah dihapus otomatis; null = belum pernah ada yang dihapus.
            $table->jsonb('purged_counts')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_vacancies', function (Blueprint $table) {
            $table->dropColumn('purged_counts');
        });

        Schema::table('hr_applicants', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'employee_id']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
