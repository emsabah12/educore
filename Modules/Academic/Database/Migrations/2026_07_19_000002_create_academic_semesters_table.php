<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_semesters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('academic_year_id')->index();
            $table->string('name', 50);
            $table->enum('type', ['GANJIL', 'GENAP'])->default('GANJIL');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes()->index();

            $table->unique(['tenant_id', 'academic_year_id', 'type', 'deleted_at'], 'academic_semesters_combo_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->onDelete('cascade');
        });

        // Pengunci Integritas Parsial Tingkat Database
        DB::statement('CREATE UNIQUE INDEX academic_semesters_single_active_idx ON academic_semesters (tenant_id) WHERE (is_active = true AND deleted_at IS NULL);');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_semesters');
    }
};
