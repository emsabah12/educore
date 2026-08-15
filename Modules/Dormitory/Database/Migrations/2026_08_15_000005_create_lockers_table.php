<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lockers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('room_id');

            $table->string('code', 100)->nullable();
            $table->boolean('is_usable')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['id', 'tenant_id'],
                'uq_lockers_id_tenant',
            );

            $table->index(
                ['room_id', 'tenant_id'],
                'idx_lockers_room_tenant',
            );

            $table->index(
                ['tenant_id', 'is_active', 'is_usable'],
                'idx_lockers_tenant_availability',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            $table->foreign(
                ['room_id', 'tenant_id'],
                'fk_lockers_room_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('rooms')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lockers');
    }
};
