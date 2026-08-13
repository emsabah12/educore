<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Required by the composite FK used by organization_units.
             * The primary key already guarantees id uniqueness; this pair
             * additionally makes tenant ownership enforceable by the DB.
             */
            $table->unique(
                ['id', 'tenant_id'],
                'uq_organizations_id_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_organizations_tenant_active',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
