<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('organization_id');

            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * This identity tuple is intentionally available for the future
             * OrganizationalAssignment FK so unit → organization → tenant
             * consistency can remain database-enforced.
             */
            $table->unique(
                ['id', 'organization_id', 'tenant_id'],
                'uq_org_units_identity_scope',
            );

            $table->index(
                ['tenant_id', 'organization_id'],
                'idx_org_units_tenant_organization',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_org_units_tenant_active',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            /*
             * A unit may only point to an Organization inside the same Tenant.
             */
            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_org_units_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_units');
    }
};
