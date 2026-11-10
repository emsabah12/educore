<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format rapor per lembaga (PRD-002 §22.5 AC-8c): kop (4 baris + logo), judul, sebutan peserta
 * didik, tempat, tanggal Hijriah, jabatan & nama kepala lembaga, penanda tangan "Mengetahui".
 * Satu baris per lembaga; tanpa baris = rapor tampil seperti bawaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_report_formats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->string('header_line1', 160)->nullable();
            $table->string('header_line2', 160)->nullable();
            $table->string('header_line3', 160)->nullable();
            $table->string('header_line4', 160)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_mime', 30)->nullable();
            $table->string('title', 80);
            $table->string('student_term', 10)->default('SISWA');
            $table->string('place', 60)->nullable();
            $table->boolean('show_hijri')->default(false);
            $table->string('principal_title', 60);
            $table->string('principal_name', 100)->nullable();
            $table->string('witness_title', 60)->nullable();
            $table->string('witness_name', 100)->nullable();
            $table->timestamps();

            $table->unique('organization_id');
            $table->index('tenant_id');

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_report_formats ADD CONSTRAINT ac_report_formats_student_term_check CHECK (student_term IN ('SISWA', 'SANTRI', 'MURID'))");
        DB::statement("ALTER TABLE ac_report_formats ADD CONSTRAINT ac_report_formats_title_not_blank_check CHECK (btrim(title) <> '' AND btrim(principal_title) <> '')");
        DB::statement('ALTER TABLE ac_report_formats ADD CONSTRAINT ac_report_formats_logo_check CHECK ((logo_path IS NULL) = (logo_mime IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_report_formats');
    }
};
