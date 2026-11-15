<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penempatan penghuni asrama (PRD-008 §5, §16 DM-2).
 *
 * - Foreign key gabungan memastikan penempatan ikut pemilik & asrama kamarnya, dan bed/loker yang dipegang PASTI
 *   milik kamar itu (bed_id/locker_id kosong = tidak dipegang; FK gabungan tidak diperiksa bila salah satu kolomnya
 *   kosong).
 * - Partial unique: satu penempatan Aktif dan satu Direncanakan per orang per yayasan; satu bed dan satu loker hanya
 *   dipegang satu penempatan Direncanakan/Aktif (rencana ikut memesan, OD-DM-04). Ini pengaman terakhir saat dua
 *   petugas menyimpan bersamaan.
 * - CHECK konsistensi status ↔ tanggal/alasan; kolom wajib diperiksa dengan `IS NOT NULL` (pelajaran DM-1:
 *   `NULL IN (...)` lolos CHECK).
 * - Bed/loker/kamar yang pernah dipakai tidak bisa dihapus permanen (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Agar penempatan bisa menunjuk (pemilik, asrama, kamar) sekaligus.
        Schema::table('dm_rooms', function (Blueprint $table) {
            $table->unique(['tenant_id', 'organization_id', 'dormitory_id', 'id']);
        });

        Schema::create('dm_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->uuid('room_id');
            $table->uuid('bed_id')->nullable();
            $table->uuid('locker_id')->nullable();
            $table->uuid('membership_id');
            $table->string('category', 10);
            $table->string('status', 15);
            $table->date('planned_on')->nullable();
            $table->date('check_in_on')->nullable();
            $table->date('check_out_on')->nullable();
            $table->string('end_reason', 15)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->string('note', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('checked_in_by')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['tenant_id', 'membership_id']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'room_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'room_id', 'bed_id'])
                ->references(['tenant_id', 'organization_id', 'room_id', 'id'])->on('dm_beds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'room_id', 'locker_id'])
                ->references(['tenant_id', 'organization_id', 'room_id', 'id'])->on('dm_lockers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('checked_in_by')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('ended_by')->references('id')->on('memberships')->nullOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX dm_placements_one_active_per_person ON dm_placements (tenant_id, membership_id) WHERE status = 'AKTIF'");
        DB::statement("CREATE UNIQUE INDEX dm_placements_one_planned_per_person ON dm_placements (tenant_id, membership_id) WHERE status = 'DIRENCANAKAN'");
        DB::statement("CREATE UNIQUE INDEX dm_placements_one_holder_per_bed ON dm_placements (bed_id) WHERE bed_id IS NOT NULL AND status IN ('DIRENCANAKAN', 'AKTIF')");
        DB::statement("CREATE UNIQUE INDEX dm_placements_one_holder_per_locker ON dm_placements (locker_id) WHERE locker_id IS NOT NULL AND status IN ('DIRENCANAKAN', 'AKTIF')");

        DB::statement("ALTER TABLE dm_placements ADD CONSTRAINT dm_placements_category_check CHECK (category IN ('PENGHUNI', 'PENGAWAS'))");
        DB::statement("ALTER TABLE dm_placements ADD CONSTRAINT dm_placements_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ('KELUAR_ASRAMA', 'KELUAR_LEMBAGA', 'MUTASI', 'AKHIR_TAHUN', 'LAINNYA'))");
        DB::statement('ALTER TABLE dm_placements ADD CONSTRAINT dm_placements_status_check CHECK ('
            ."(status = 'DIRENCANAKAN' AND check_in_on IS NULL AND check_out_on IS NULL AND end_reason IS NULL AND cancel_reason IS NULL) OR "
            ."(status = 'AKTIF' AND check_in_on IS NOT NULL AND check_out_on IS NULL AND end_reason IS NULL AND cancel_reason IS NULL) OR "
            ."(status = 'SELESAI' AND check_in_on IS NOT NULL AND check_out_on IS NOT NULL AND check_out_on >= check_in_on AND end_reason IS NOT NULL AND cancel_reason IS NULL) OR "
            ."(status = 'DIBATALKAN' AND check_in_on IS NULL AND check_out_on IS NULL AND end_reason IS NULL AND cancel_reason IS NOT NULL AND btrim(cancel_reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_placements');

        Schema::table('dm_rooms', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'organization_id', 'dormitory_id', 'id']);
        });
    }
};
