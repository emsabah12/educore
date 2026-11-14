<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data resmi lembaga (PRD-000 §4.6; PRD-001 §21.1, OD-HR-26): NPSN, status negeri/swasta, wilayah.
 * Semua opsional; hanya diisi untuk node bertipe LEMBAGA (dicek aplikasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('npsn', 8)->nullable();
            $table->string('ownership', 10)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('regency', 100)->nullable();
            $table->string('province', 100)->nullable();
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_ownership_check CHECK (ownership IS NULL OR ownership IN ('NEGERI', 'SWASTA'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_npsn_check CHECK (npsn IS NULL OR npsn ~ '^[0-9]{8}$')");
        DB::statement('CREATE UNIQUE INDEX organizations_tenant_npsn_unique ON organizations (tenant_id, npsn) WHERE npsn IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_tenant_npsn_unique');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_npsn_check');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_ownership_check');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['npsn', 'ownership', 'district', 'regency', 'province']);
        });
    }
};
