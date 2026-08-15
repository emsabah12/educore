<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('dormitory_id');

            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Keep a tenant-qualified identity available for Room without
             * duplicating Dormitory organizational ownership on Building.
             */
            $table->unique(
                ['id', 'tenant_id'],
                'uq_buildings_id_tenant',
            );

            $table->index(
                ['dormitory_id', 'tenant_id'],
                'idx_buildings_dormitory_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_buildings_tenant_active',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            /*
             * A Building may only belong to a Dormitory in its own Tenant.
             */
            $table->foreign(
                ['dormitory_id', 'tenant_id'],
                'fk_buildings_dormitory_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('dormitories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
