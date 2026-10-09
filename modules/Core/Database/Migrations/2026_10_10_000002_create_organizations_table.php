<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Node pohon lembaga: LEMBAGA, UNIT, atau BIRO (PRD-000 §4.2, §4.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('type', 20);
            $table->string('category', 20)->nullable();
            $table->string('jenjang', 30)->nullable();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);

            // Dibutuhkan sebagai target foreign key gabungan di bawah.
            $table->unique(['tenant_id', 'id']);

            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'jenjang']);

            // Induk WAJIB berada di tenant yang sama. Karena foreign key memakai
            // pasangan (tenant_id, parent_id), database menolak induk dari tenant lain.
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_check CHECK (type IN ('LEMBAGA', 'UNIT', 'BIRO'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_category_check CHECK (category IS NULL OR category IN ('FORMAL', 'NONFORMAL', 'PESANTREN'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_not_own_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');
        // LEMBAGA wajib punya category & jenjang; UNIT dan BIRO tidak memakainya.
        DB::statement(<<<'SQL'
            ALTER TABLE organizations ADD CONSTRAINT organizations_type_attributes_check CHECK (
                (type = 'LEMBAGA' AND category IS NOT NULL AND jenjang IS NOT NULL)
                OR (type IN ('UNIT', 'BIRO') AND category IS NULL AND jenjang IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
