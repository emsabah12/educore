<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ekstrakurikuler, kepribadian & peringkat kelas di rapor (PRD-002 §22.6 AC-8d):
 * - `ac_report_items`: butir per lembaga — EKSKUL atau KEPRIBADIAN; kepribadian membawa keterangan
 *   otomatis per huruf predikat (`descriptions`: huruf → teks).
 * - `ac_report_item_grades`: huruf (+ keterangan pengganti) per kelas × semester × siswa × butir.
 * - `ac_report_formats.show_rank`: tampilkan peringkat kelas di rapor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_report_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->string('kind', 12);
            $table->string('name', 60);
            $table->smallInteger('sort_order')->default(0);
            $table->jsonb('descriptions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_report_items ADD CONSTRAINT ac_report_items_kind_check CHECK (kind IN ('EKSKUL', 'KEPRIBADIAN'))");
        DB::statement("ALTER TABLE ac_report_items ADD CONSTRAINT ac_report_items_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE ac_report_items ADD CONSTRAINT ac_report_items_descriptions_check CHECK (descriptions IS NULL OR jsonb_typeof(descriptions) = 'object')");
        DB::statement('CREATE UNIQUE INDEX ac_report_items_name_unique ON ac_report_items (organization_id, kind, lower(name))');

        Schema::create('ac_report_item_grades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->foreignUuid('class_id')->constrained('ac_classes')->restrictOnDelete();
            $table->foreignUuid('term_id')->constrained('ac_terms')->restrictOnDelete();
            $table->foreignUuid('enrollment_id')->constrained('ac_enrollments')->restrictOnDelete();
            $table->foreignUuid('item_id')->constrained('ac_report_items')->restrictOnDelete();
            $table->string('letter', 2)->nullable();
            $table->string('note', 100)->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'term_id', 'enrollment_id', 'item_id']);
            $table->index(['tenant_id', 'organization_id']);
        });

        DB::statement('ALTER TABLE ac_report_item_grades ADD CONSTRAINT ac_report_item_grades_filled_check CHECK (letter IS NOT NULL OR note IS NOT NULL)');

        Schema::table('ac_report_formats', function (Blueprint $table) {
            $table->boolean('show_rank')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ac_report_formats', function (Blueprint $table) {
            $table->dropColumn('show_rank');
        });

        Schema::dropIfExists('ac_report_item_grades');
        Schema::dropIfExists('ac_report_items');
    }
};
