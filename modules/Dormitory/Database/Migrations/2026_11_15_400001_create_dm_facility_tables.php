<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fasilitas asrama (PRD-008 §4, §15 DM-1): asrama → gedung → kamar → bed & loker.
 *
 * - Semua tabel menyimpan `tenant_id` + `organization_id` (node pemilik asrama). Foreign key gabungan
 *   memastikan anak selalu ikut pemilik & yayasan induknya: gedung tidak bisa milik lembaga lain dari asramanya,
 *   kamar tidak bisa menunjuk gedung di asrama lain, bed/loker tidak bisa pindah yayasan.
 * - Kode: huruf besar, angka, `-` (maks. 30); unik per induknya.
 * - Jenis kelamin di gedung (L/P), kamar mengikuti gedung (PRD-008 §4.2).
 * - Komposisi Khusus santri ⇒ slot pengawas 0; Santri + pengawas ⇒ slot pengawas 1–5 (§4.3).
 * - Bed/loker tidak tersedia wajib punya alasan; yang tersedia tidak punya alasan (§4.5).
 * - Status "terisi" tidak disimpan di bed/loker; dihitung dari penempatan (DM-2).
 */
return new class extends Migration
{
    private const CODE_PATTERN = "'^[A-Z0-9]+(-[A-Z0-9]+)*$'";

    public function up(): void
    {
        Schema::create('dm_dormitories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('default_year_end_policy', 10)->default('TETAP');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        Schema::create('dm_buildings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->char('gender', 1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->unique(['dormitory_id', 'code']);
            $table->unique(['tenant_id', 'organization_id', 'dormitory_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('dm_dormitories')->restrictOnDelete();
        });

        Schema::create('dm_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->uuid('building_id');
            $table->string('code', 30);
            $table->string('name', 100)->nullable();
            $table->string('capacity_basis', 10);
            $table->string('composition', 20);
            $table->unsignedSmallInteger('supervisor_slots')->default(0);
            $table->string('year_end_policy', 10)->default('TETAP');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);
            $table->index(['dormitory_id']);
            $table->unique(['building_id', 'code']);
            $table->unique(['tenant_id', 'organization_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'building_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_buildings')->restrictOnDelete();
        });

        foreach (['dm_beds', 'dm_lockers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('organization_id');
                $table->uuid('room_id');
                $table->string('code', 30);
                $table->string('status', 15)->default('TERSEDIA');
                $table->string('unavailable_reason', 10)->nullable();
                $table->string('unavailable_note', 200)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['tenant_id', 'organization_id']);
                $table->unique(['room_id', 'code']);
                $table->unique(['tenant_id', 'organization_id', 'room_id', 'id']);

                $table->foreign(['tenant_id', 'organization_id', 'room_id'])
                    ->references(['tenant_id', 'organization_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            });
        }

        $pattern = self::CODE_PATTERN;

        foreach (['dm_dormitories', 'dm_buildings', 'dm_rooms', 'dm_beds', 'dm_lockers'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_format_check CHECK (code ~ {$pattern})");
        }

        foreach (['dm_dormitories', 'dm_buildings'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_name_not_blank_check CHECK (btrim(name) <> '')");
        }

        DB::statement("ALTER TABLE dm_rooms ADD CONSTRAINT dm_rooms_name_not_blank_check CHECK (name IS NULL OR btrim(name) <> '')");
        DB::statement("ALTER TABLE dm_dormitories ADD CONSTRAINT dm_dormitories_policy_check CHECK (default_year_end_policy IN ('TETAP', 'KOSONGKAN'))");
        DB::statement("ALTER TABLE dm_buildings ADD CONSTRAINT dm_buildings_gender_check CHECK (gender IN ('L', 'P'))");
        DB::statement("ALTER TABLE dm_rooms ADD CONSTRAINT dm_rooms_basis_check CHECK (capacity_basis IN ('BED', 'LOKER', 'BED_LOKER'))");
        DB::statement("ALTER TABLE dm_rooms ADD CONSTRAINT dm_rooms_policy_check CHECK (year_end_policy IN ('TETAP', 'KOSONGKAN'))");
        DB::statement("ALTER TABLE dm_rooms ADD CONSTRAINT dm_rooms_composition_check CHECK ((composition = 'KHUSUS_SANTRI' AND supervisor_slots = 0) OR (composition = 'SANTRI_PENGAWAS' AND supervisor_slots BETWEEN 1 AND 5))");

        foreach (['dm_beds', 'dm_lockers'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_status_check CHECK ("
                ."(status = 'TERSEDIA' AND unavailable_reason IS NULL AND unavailable_note IS NULL) OR "
                ."(status = 'TIDAK_TERSEDIA' AND unavailable_reason IS NOT NULL AND unavailable_reason IN ('RUSAK', 'PERBAIKAN', 'DITUTUP', 'LAINNYA')))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_lockers');
        Schema::dropIfExists('dm_beds');
        Schema::dropIfExists('dm_rooms');
        Schema::dropIfExists('dm_buildings');
        Schema::dropIfExists('dm_dormitories');
    }
};
