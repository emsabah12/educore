<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id');

            /*
             * Initial canonical types:
             * EMAIL
             * PHONE
             *
             * Tidak menggunakan database ENUM.
             */
            $table->string('type', 20);

            /*
             * Human-readable representation.
             */
            $table->string('value', 320);

            /*
             * Canonical representation untuk equality/search.
             *
             * Contoh:
             * PHONE → E.164
             * EMAIL → normalization policy application layer
             */
            $table->string('normalized_value', 320);

            /*
             * Contoh:
             * personal
             * work
             * emergency
             */
            $table->string('label', 50)->nullable();

            $table->boolean('is_primary')
                ->default(false);

            $table->timestamp('verified_at')
                ->nullable();

            $table->timestamps();

            /*
             * Person yang sama tidak boleh memiliki
             * contact value identik untuk type yang sama.
             *
             * Tetapi dua Person berbeda boleh berbagi
             * nomor/email keluarga.
             */
            $table->unique([
                'person_id',
                'type',
                'normalized_value',
            ]);

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_contacts');
    }
};
