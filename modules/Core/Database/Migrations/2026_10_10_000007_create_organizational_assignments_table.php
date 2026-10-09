<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan seorang anggota (Membership) di pohon lembaga (PRD-000 §4.4, §5).
 *
 *   organization_id terisi, jenjang_filter kosong  → penugasan struktural (node + turunannya)
 *   organization_id terisi, jenjang_filter terisi  → penugasan fungsional di bawah node itu
 *   organization_id kosong, jenjang_filter terisi  → penugasan fungsional di seluruh Yayasan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->uuid('organization_id')->nullable();
            $table->string('jenjang_filter', 30)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id']);
            $table->index(['tenant_id', 'organization_id']);

            // Membership dan lembaga WAJIB dari yayasan yang sama dengan penugasan ini.
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE organizational_assignments ADD CONSTRAINT organizational_assignments_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE organizational_assignments ADD CONSTRAINT organizational_assignments_target_check CHECK (organization_id IS NOT NULL OR jenjang_filter IS NOT NULL)');

        // Penugasan yang sama tidak boleh dobel. COALESCE dipakai karena di PostgreSQL
        // dua nilai NULL dianggap berbeda sehingga UNIQUE biasa tidak cukup.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organizational_assignments_unique_target
            ON organizational_assignments (
                membership_id,
                COALESCE(organization_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(jenjang_filter, '')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizational_assignments');
    }
};
