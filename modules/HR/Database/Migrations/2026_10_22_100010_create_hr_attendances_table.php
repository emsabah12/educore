<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi pegawai (PRD-001 §14, HR-4): satu baris per pegawai per tanggal.
 *
 * - Lembaga = penempatan utama saat dicatat (lembar petugas & rekap per lembaga).
 * - Jam wajib (`expected_*`) disalin saat dicatat agar riwayat tidak berubah bila jadwal/jam kerja diubah.
 * - Sumber jam: MANDIRI (pegawai absen sendiri) atau PETUGAS.
 * - Cuti tidak disimpan di sini; ditampilkan dari `hr_leave_requests`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('organization_id');
            $table->date('date');
            $table->string('status', 12);
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('check_in_source', 10)->nullable();
            $table->string('check_out_source', 10)->nullable();
            $table->time('expected_start')->nullable();
            $table->time('expected_end')->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_minutes')->default(0);
            $table->string('note', 300)->nullable();
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['tenant_id', 'organization_id', 'date']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_status_check CHECK (status IN ('HADIR', 'SAKIT', 'IZIN', 'ALPA', 'DINAS_LUAR'))");
        DB::statement("ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_source_check CHECK ((check_in_source IS NULL OR check_in_source IN ('MANDIRI', 'PETUGAS')) AND (check_out_source IS NULL OR check_out_source IN ('MANDIRI', 'PETUGAS')))");
        // Jam hanya untuk Hadir/Dinas luar; jam pulang butuh jam masuk dan tidak sebelum jam masuk.
        DB::statement("ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_times_check CHECK ((check_in IS NULL OR status IN ('HADIR', 'DINAS_LUAR')) AND (check_out IS NULL OR (check_in IS NOT NULL AND check_out >= check_in)) AND ((check_in IS NULL) = (check_in_source IS NULL)) AND ((check_out IS NULL) = (check_out_source IS NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendances');
    }
};
