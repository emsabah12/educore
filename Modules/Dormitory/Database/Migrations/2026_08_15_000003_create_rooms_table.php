<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('building_id');

            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->enum(
                'capacity_basis',
                ['BED', 'LOCKER', 'BED_AND_LOCKER'],
            );
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Keep a tenant-qualified identity available for Bed/Locker while
             * deriving Dormitory ownership through the canonical Building.
             */
            $table->unique(
                ['id', 'tenant_id'],
                'uq_rooms_id_tenant',
            );

            $table->index(
                ['building_id', 'tenant_id'],
                'idx_rooms_building_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active'],
                'idx_rooms_tenant_active',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            /*
             * A Room may only belong to a Building in its own Tenant.
             */
            $table->foreign(
                ['building_id', 'tenant_id'],
                'fk_rooms_building_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('buildings')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
