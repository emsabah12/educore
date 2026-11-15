<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan wali kamar (PRD-008 §6, §17 DM-3): pegawai yang MENGAWASI kamar. Terpisah dari tinggal di kamar
 * (penempatan kategori Pengawas, dm_placements).
 *
 * - FK gabungan ke kamar: penugasan ikut pemilik & asrama kamarnya.
 * - Partial unique: satu penugasan aktif (end_on kosong) per orang per kamar. Batas 3 per kamar dijaga service
 *   dengan mengunci baris kamar.
 * - Penugasan tidak dihapus; diakhiri dengan end_on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_room_supervisors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->uuid('room_id');
            $table->uuid('membership_id');
            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->string('note', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'end_on']);
            $table->index(['tenant_id', 'membership_id']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'room_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('ended_by')->references('id')->on('memberships')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX dm_room_supervisors_one_active_per_person ON dm_room_supervisors (room_id, membership_id) WHERE end_on IS NULL');
        DB::statement('ALTER TABLE dm_room_supervisors ADD CONSTRAINT dm_room_supervisors_dates_check CHECK (end_on IS NULL OR end_on >= start_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_room_supervisors');
    }
};
