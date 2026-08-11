<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Canonical human name.
             *
             * `name` tetap menjadi display/full name utama agar
             * existing Person domain tidak perlu kehilangan contract
             * PersonName yang sudah stabil.
             */
            $table->string('name', 255);

            /*
             * Structured legal/personal name components.
             *
             * Nullable karena:
             * - tidak semua budaya menggunakan family name;
             * - data legacy mungkin belum lengkap;
             * - Person tetap dapat direpresentasikan melalui `name`.
             */
            $table->string('given_name', 255)->nullable();
            $table->string('middle_name', 255)->nullable();
            $table->string('family_name', 255)->nullable();

            /*
             * Basic biographical identity.
             */
            $table->date('birth_date')->nullable();
            $table->string('birth_place_name', 255)->nullable();
            $table->char('birth_country_code', 2)->nullable();

            /*
             * Legal demographic markers.
             *
             * Valid values akan dijaga oleh domain/application enum,
             * bukan database ENUM agar schema tetap portable dan
             * mudah berevolusi.
             */
            $table->char('legal_sex', 1)->nullable();
            $table->string('civil_status', 32)->nullable();

            /*
             * Person lifecycle:
             * ACTIVE / INACTIVE / ARCHIVED / DECEASED.
             */
            $table->string('status', 32);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
