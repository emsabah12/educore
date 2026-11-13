<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presensi lokasi HP (PRD-001 §14.6, HR-4d; OD-HR-22).
 *
 * - `hr_work_locations`: titik lokasi & radius absen per lembaga (milik HR; tabel lembaga Core tidak diubah).
 * - `hr_attendances`: hasil pemeriksaan lokasi absen mandiri masuk/pulang — lembaga terdekat, jarak & akurasi (meter),
 *   hasil DI_DALAM / DI_LUAR / TIDAK_TERSEDIA. Koordinat HP TIDAK disimpan (OD-HR-22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_work_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedSmallInteger('radius');
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_work_locations ADD CONSTRAINT hr_work_locations_point_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND radius BETWEEN 50 AND 1000)');

        Schema::table('hr_attendances', function (Blueprint $table) {
            foreach (['check_in', 'check_out'] as $prefix) {
                $table->string("{$prefix}_location", 15)->nullable();
                $table->unsignedInteger("{$prefix}_distance")->nullable();
                $table->unsignedInteger("{$prefix}_accuracy")->nullable();
                $table->uuid("{$prefix}_location_org_id")->nullable();
            }
        });

        DB::statement("ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_location_check CHECK ((check_in_location IS NULL OR check_in_location IN ('DI_DALAM', 'DI_LUAR', 'TIDAK_TERSEDIA')) AND (check_out_location IS NULL OR check_out_location IN ('DI_DALAM', 'DI_LUAR', 'TIDAK_TERSEDIA')))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hr_attendances DROP CONSTRAINT IF EXISTS hr_attendances_location_check');

        Schema::table('hr_attendances', function (Blueprint $table) {
            foreach (['check_in', 'check_out'] as $prefix) {
                $table->dropColumn(["{$prefix}_location", "{$prefix}_distance", "{$prefix}_accuracy", "{$prefix}_location_org_id"]);
            }
        });

        Schema::dropIfExists('hr_work_locations');
    }
};
