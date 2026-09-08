<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §PRD Subscription & Custom Role — siklus status add-on per tenant:
 *
 * trial -> active -> (dicabut/downgrade) -> locked_readonly
 *   -> (readonly_until lewat) -> locked_hidden -> (upgrade lagi) -> active
 *
 * `locked_at`: kapan add-on berhenti efektif (dicabut/expired).
 * `readonly_until`: dihitung `locked_at` + `grace_period_days` milik
 * paket tenant SAAT addon dicabut — disimpan eksplisit di sini (bukan
 * dihitung ulang tiap kali) supaya perubahan `grace_period_days`
 * paket di kemudian hari TIDAK mengubah tenggat yang sudah berjalan
 * untuk tenant yang sudah terlanjur di-lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('addon_id');
            $table->string('status', 20)->default('trial');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('readonly_until')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'addon_id'],
                'uq_tenant_addons_tenant_addon',
            );

            $table->foreign('tenant_id', 'fk_tenant_addons_tenant')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('addon_id', 'fk_tenant_addons_addon')
                ->references('id')
                ->on('addons')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE tenant_addons
                ADD CONSTRAINT chk_tenant_addons_status
                CHECK (status IN ('trial', 'active', 'locked_readonly', 'locked_hidden'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_addons');
    }
};
