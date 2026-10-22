<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kelola cuti Admin SDM (PRD-001 §13.5, HR-3d): riwayat keputusan juga mencatat
 * - DICATAT = cuti dicatat Admin SDM atas nama pegawai (langsung disetujui);
 * - BATAL   = cuti yang sudah disetujui dibatalkan Admin SDM (alasan wajib; saldo kembali).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_leave_decisions DROP CONSTRAINT hr_leave_decisions_decision_check');
        DB::statement("ALTER TABLE hr_leave_decisions ADD CONSTRAINT hr_leave_decisions_decision_check CHECK (decision IN ('SETUJU', 'TOLAK', 'DILEWATI', 'DICATAT', 'BATAL') AND step IN (1, 2))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM hr_leave_decisions WHERE decision IN ('DICATAT', 'BATAL')");
        DB::statement('ALTER TABLE hr_leave_decisions DROP CONSTRAINT hr_leave_decisions_decision_check');
        DB::statement("ALTER TABLE hr_leave_decisions ADD CONSTRAINT hr_leave_decisions_decision_check CHECK (decision IN ('SETUJU', 'TOLAK', 'DILEWATI') AND step IN (1, 2))");
    }
};
