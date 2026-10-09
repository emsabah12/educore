<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership = keikutsertaan seorang Person di sebuah Tenant/yayasan (PRD-000 §5, [LAMA] ADR-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['person_id', 'tenant_id']);

            // Target foreign key gabungan dari organizational_assignments.
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
