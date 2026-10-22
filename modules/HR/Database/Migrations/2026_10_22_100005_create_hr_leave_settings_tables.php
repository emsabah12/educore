<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti/izin dan hari libur kalender kerja HR.
 *
 * - Jenis cuti: kode tetap (aturan sama dengan katalog SDM), berjatah = punya jatah per tahun,
 *   batas hari per pengajuan opsional. Tidak dihapus; cukup dinonaktifkan.
 * - Hari libur (tanggal merah) berlaku untuk seluruh yayasan. Libur mingguan per jenjang/lembaga
 *   memakai aturan berjenjang `hr.weekly_off_days` (Core scoped settings), bukan tabel di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->boolean('has_quota')->default(false);
            $table->unsignedSmallInteger('annual_quota')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
        DB::statement("ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_quota_check CHECK (has_quota = (annual_quota IS NOT NULL) AND (annual_quota IS NULL OR annual_quota BETWEEN 1 AND 366))');
        DB::statement('ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_max_days_check CHECK (max_days IS NULL OR max_days BETWEEN 1 AND 366)');

        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index(['tenant_id', 'start_date']);
        });

        DB::statement('ALTER TABLE hr_holidays ADD CONSTRAINT hr_holidays_dates_check CHECK (end_date >= start_date)');
        DB::statement("ALTER TABLE hr_holidays ADD CONSTRAINT hr_holidays_name_not_blank_check CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_holidays');
        Schema::dropIfExists('hr_leave_types');
    }
};
