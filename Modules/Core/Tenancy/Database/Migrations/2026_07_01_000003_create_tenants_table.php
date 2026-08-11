<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            /*
             * Canonical tenant identity.
             *
             * UUIDv7 dihasilkan application/model layer.
             */
            $table->uuid('id')->primary();

            /*
             * Human-readable tenant identity.
             */
            $table->string('name', 255);

            /*
             * Routing identifiers.
             *
             * Keduanya bukan authorization source.
             */
            $table->string('subdomain', 50)
                ->unique();

            $table->string('domain', 255)
                ->nullable()
                ->unique();

            /*
             * Tenant operational lifecycle.
             */
            $table->boolean('is_active')
                ->default(true);

            /*
             * Flexible tenant-level configuration.
             *
             * Tidak digunakan sebagai tempat menyimpan
             * domain entities atau authorization state.
             */
            $table->jsonb('settings')
                ->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
