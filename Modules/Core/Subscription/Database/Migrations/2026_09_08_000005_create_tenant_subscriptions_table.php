<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket AKTIF SAAT INI per tenant — satu baris per tenant, bukan
 * tabel riwayat. Perubahan paket (upgrade/downgrade) dicatat lewat
 * `audit_logs` yang sudah ada, konsisten dengan pola audit trail yang
 * sudah dipakai di seluruh modul Platform, bukan tabel histori
 * terpisah.
 *
 * `status`: `trial` (dipilih tenant, menunggu pembayaran/approval,
 * "berlaku self-service penuh" sesuai keputusan PRD) atau `active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('plan_id');
            $table->string('status', 20)->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'uq_tenant_subscriptions_tenant');

            $table->foreign('tenant_id', 'fk_tenant_subscriptions_tenant')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('plan_id', 'fk_tenant_subscriptions_plan')
                ->references('id')
                ->on('subscription_plans')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE tenant_subscriptions
                ADD CONSTRAINT chk_tenant_subscriptions_status
                CHECK (status IN ('trial', 'active'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
