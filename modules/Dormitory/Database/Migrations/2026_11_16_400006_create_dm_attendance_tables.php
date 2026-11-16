<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Absen asrama / roll call (PRD-008 §12 DM-7, §22).
 *
 * - dm_attendance_kinds: jenis absen per asrama (mis. Subuh, Malam), maks. 5 aktif (dijaga service). Nama unik per
 *   asrama tanpa membedakan huruf besar/kecil. Jenis yang sudah dipakai tidak dihapus, hanya dinonaktifkan.
 * - dm_attendance_sessions: satu sesi = kamar + tanggal + jenis [OWNER, 2026-11-16: per kamar]. FK gabungan ke
 *   kamar dan ke jenis menjamin keduanya milik asrama & pemilik yang sama.
 * - dm_attendance_records: status per penghuni (santri & pengawas [OWNER]) pada sesi itu: H Hadir, I Izin,
 *   S Sakit, T Tidak ada. Mengacu ke penempatan yang berlaku pada tanggal itu.
 * - Absen tidak dihapus; koreksi menimpa isi sesi yang sama (tercatat di audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_attendance_kinds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->string('name', 30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['dormitory_id', 'position']);
            $table->unique(['tenant_id', 'organization_id', 'dormitory_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('dm_dormitories')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX dm_attendance_kinds_name_unique ON dm_attendance_kinds (dormitory_id, lower(name))');
        DB::statement('ALTER TABLE dm_attendance_kinds ADD CONSTRAINT dm_attendance_kinds_name_check CHECK (length(btrim(name)) > 0)');

        Schema::create('dm_attendance_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->uuid('room_id');
            $table->uuid('kind_id');
            $table->date('date');
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'kind_id', 'date']);
            $table->index(['dormitory_id', 'date']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'room_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'kind_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_attendance_kinds')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('memberships')->nullOnDelete();
        });

        Schema::create('dm_attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('session_id')->constrained('dm_attendance_sessions')->cascadeOnDelete();
            $table->foreignUuid('placement_id')->constrained('dm_placements')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->char('status', 1);
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'placement_id']);
            $table->index(['tenant_id', 'membership_id']);

            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE dm_attendance_records ADD CONSTRAINT dm_attendance_records_status_check CHECK (status IN ('H', 'I', 'S', 'T'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_attendance_records');
        Schema::dropIfExists('dm_attendance_sessions');
        Schema::dropIfExists('dm_attendance_kinds');
    }
};
