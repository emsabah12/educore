<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Person = identitas manusia global (PRD-000 §5, [LAMA] ADR-013).
 *
 * Sengaja minimal (OD-05): data pribadi lain baru ditambahkan
 * saat ada kebutuhan nyata dari PRD HR/Academic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->char('gender', 1)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_gender_check CHECK (gender IS NULL OR gender IN ('L', 'P'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_name_not_blank_check CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
