<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('membership_id')->unique();
            $table->string('nip', 50)->nullable();
            $table->string('jabatan', 100);
            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'tenant_id',
                'nip',
            ]);
            $table->index([
                'tenant_id',
                'created_at',
            ]);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
