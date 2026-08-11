<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Canonical machine-readable role identifier.
             *
             * Contoh:
             * tenant.owner
             * admin
             * teacher
             */
            $table->string('name', 150)->unique();

            /*
             * Human-readable label.
             */
            $table->string('display_name', 255);

            $table->string('description', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
