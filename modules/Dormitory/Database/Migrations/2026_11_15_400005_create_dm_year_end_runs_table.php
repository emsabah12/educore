<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan Proses akhir tahun asrama (PRD-008 §9, §19.2). Satu asrama hanya bisa diproses sekali per tahun
 * ajaran (unique). Penempatan yang diakhiri tetap tersimpan di dm_placements (alasan AKHIR_TAHUN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_year_end_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->string('academic_year_name', 50);
            $table->date('academic_year_end');
            $table->unsignedInteger('emptied');
            $table->unsignedInteger('kept');
            $table->unsignedInteger('review');
            $table->uuid('ran_by')->nullable();
            $table->timestamps();

            $table->unique(['dormitory_id', 'academic_year_name']);

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('dm_dormitories')->restrictOnDelete();
            $table->foreign('ran_by')->references('id')->on('memberships')->nullOnDelete();
        });

        DB::statement('ALTER TABLE dm_year_end_runs ADD CONSTRAINT dm_year_end_runs_counts_check CHECK (emptied >= 0 AND kept >= 0 AND review >= 0 AND length(btrim(academic_year_name)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_year_end_runs');
    }
};
