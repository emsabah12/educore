<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender hari libur (PRD-002 §18 P4).
 *
 * - Hari libur mingguan per kalender akademik: daftar nomor hari 1 (Senin) – 7 (Ahad), bawaan [7].
 * - Hari libur per tahun ajaran: nama + rentang tanggal di dalam tahun ajaran (dicek service).
 *   Boleh tumpang tindih; hari efektif tetap dihitung sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ac_academic_calendars', function (Blueprint $table) {
            $table->jsonb('weekly_off_days')->default(DB::raw("'[7]'::jsonb"));
        });

        DB::statement("ALTER TABLE ac_academic_calendars ADD CONSTRAINT ac_academic_calendars_weekly_off_days_check CHECK (jsonb_typeof(weekly_off_days) = 'array' AND jsonb_array_length(weekly_off_days) <= 6)");

        Schema::create('ac_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('academic_year_id');
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['academic_year_id', 'start_date']);

            $table->foreign(['tenant_id', 'academic_year_id'])
                ->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_holidays ADD CONSTRAINT ac_holidays_dates_check CHECK (end_date >= start_date)');
        DB::statement("ALTER TABLE ac_holidays ADD CONSTRAINT ac_holidays_name_not_blank_check CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_holidays');

        DB::statement('ALTER TABLE ac_academic_calendars DROP CONSTRAINT ac_academic_calendars_weekly_off_days_check');

        Schema::table('ac_academic_calendars', function (Blueprint $table) {
            $table->dropColumn('weekly_off_days');
        });
    }
};
