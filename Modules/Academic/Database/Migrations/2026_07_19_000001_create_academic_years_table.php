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
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes()->index();

            $table->unique(['tenant_id', 'name', 'deleted_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // Pengunci Integritas Parsial Tingkat Database
        DB::statement('CREATE UNIQUE INDEX academic_years_single_active_idx ON academic_years (tenant_id) WHERE (is_active = true AND deleted_at IS NULL);');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
