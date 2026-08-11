<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Canonical machine-readable permission identifier.
             *
             * Contoh:
             * academic.grade.update
             * notification.dispatch
             */
            $table->string('name', 191)->unique();

            $table->string('display_name', 255);

            $table->string('description', 255)->nullable();

            /*
             * Module yang mendeklarasikan permission.
             *
             * Ini metadata ownership/discovery,
             * bukan tenant scope.
             */
            $table->string('module', 100)
                ->default('Core');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
