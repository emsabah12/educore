<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender akademik (PRD-002 §4.2, §4.4): kalender → tahun ajaran → semester.
 *
 * - Satu kalender default ("Kalender Yayasan") per yayasan. Kalender lain (mis. Kalender MDA)
 *   dipakai lembaga lewat aturan berjenjang `academic.calendar`.
 * - Maksimal satu tahun ajaran berjalan per kalender.
 * - Tahun ajaran tidak tumpang tindih & semester berada di dalam tahun ajarannya: dicek service
 *   (butuh btree_gist bila dijaga database; sengaja tidak dipakai, sama dengan HR-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_academic_calendars', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE ac_academic_calendars ADD CONSTRAINT ac_academic_calendars_name_not_blank_check CHECK (btrim(name) <> '')");
        // Nama kalender unik per yayasan, tanpa beda huruf besar/kecil.
        DB::statement('CREATE UNIQUE INDEX ac_academic_calendars_name_unique ON ac_academic_calendars (tenant_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX ac_academic_calendars_one_default_unique ON ac_academic_calendars (tenant_id) WHERE is_default');

        Schema::create('ac_academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('calendar_id');
            $table->string('name', 9);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['calendar_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['calendar_id', 'start_date']);

            $table->foreign(['tenant_id', 'calendar_id'])
                ->references(['tenant_id', 'id'])->on('ac_academic_calendars')->restrictOnDelete();
        });

        // Nama "YYYY/YYYY" dengan tahun kedua = tahun pertama + 1 (berlaku juga untuk tahun Hijriah, mis. 1447/1448).
        DB::statement(<<<'SQL'
            ALTER TABLE ac_academic_years ADD CONSTRAINT ac_academic_years_name_format_check CHECK (
                CASE
                    WHEN name ~ '^[0-9]{4}/[0-9]{4}$' THEN substr(name, 6, 4)::int = substr(name, 1, 4)::int + 1
                    ELSE false
                END
            )
        SQL);
        DB::statement('ALTER TABLE ac_academic_years ADD CONSTRAINT ac_academic_years_dates_check CHECK (end_date > start_date)');
        DB::statement('CREATE UNIQUE INDEX ac_academic_years_one_current_unique ON ac_academic_years (calendar_id) WHERE is_current');

        Schema::create('ac_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('academic_year_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['academic_year_id', 'sequence']);
            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'academic_year_id'])
                ->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_terms ADD CONSTRAINT ac_terms_sequence_check CHECK (sequence BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE ac_terms ADD CONSTRAINT ac_terms_dates_check CHECK (end_date >= start_date)');
        DB::statement("ALTER TABLE ac_terms ADD CONSTRAINT ac_terms_name_not_blank_check CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_terms');
        Schema::dropIfExists('ac_academic_years');
        Schema::dropIfExists('ac_academic_calendars');
    }
};
