<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dormitories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('organization_id');
            $table->uuid('organization_unit_id')->nullable();

            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Keep a tenant-qualified identity available for downstream
             * Dormitory resources without making Dormitory a Core scope type.
             */
            $table->unique(
                ['id', 'tenant_id'],
                'uq_dormitories_id_tenant',
            );

            $table->index(
                ['tenant_id', 'organization_id'],
                'idx_dormitories_tenant_organization',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_dormitories_tenant_active',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            /*
             * A Dormitory may only select an Organization in its own Tenant.
             */
            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_dormitories_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            /*
             * When present, the OrganizationUnit must belong to the same
             * Organization and Tenant already owned by the Dormitory.
             */
            $table->foreign(
                ['organization_unit_id', 'organization_id', 'tenant_id'],
                'fk_dormitories_org_unit_scope',
            )
                ->references(['id', 'organization_id', 'tenant_id'])
                ->on('organization_units')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dormitories');
    }
};
