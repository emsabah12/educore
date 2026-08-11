<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id');

            /*
             * REGISTERED / DOMICILE / MAILING.
             */
            $table->string('type', 32);

            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();

            /*
             * Kita sengaja belum mengikat address ke geographic
             * master tables yang belum mempunyai requirement.
             */
            $table->string('locality', 150)->nullable();
            $table->string('administrative_area', 150)->nullable();
            $table->string('postal_code', 32)->nullable();

            $table->char('country_code', 2);

            $table->boolean('is_primary')
                ->default(false);

            /*
             * Mendukung address history tanpa membutuhkan
             * soft-delete sebagai lifecycle mechanism.
             */
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->timestamps();

            $table->index('person_id');

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_addresses');
    }
};
