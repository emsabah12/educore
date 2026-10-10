<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan berjenjang / scoped settings (PRD-000 §8).
 *
 *   organization_id kosong            → aturan tingkat Yayasan
 *   organization_id terisi            → aturan di node itu (berlaku ke turunannya)
 *   jenjang terisi                    → hanya untuk lembaga berjenjang itu, mis. "semua MDA"
 *   is_enforced = true                → dikunci: node di bawahnya tidak bisa menimpa
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->string('jenjang', 30)->nullable();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->boolean('is_enforced')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'key']);

            // Node WAJIB dari yayasan yang sama dengan aturan ini.
            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE scoped_settings ADD CONSTRAINT scoped_settings_key_format_check CHECK (key ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')");

        // Satu nilai per (node, jenjang, key). COALESCE karena NULL dianggap berbeda di UNIQUE biasa.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX scoped_settings_unique_target
            ON scoped_settings (
                tenant_id,
                COALESCE(organization_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(jenjang, ''),
                key
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_settings');
    }
};
