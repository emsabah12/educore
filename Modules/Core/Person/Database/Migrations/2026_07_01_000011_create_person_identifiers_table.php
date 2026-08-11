<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id');

            /*
             * NATIONAL_ID
             * PASSPORT
             * VISA
             * RESIDENCE_PERMIT
             */
            $table->string('type', 50);

            /*
             * Current scope identifier kita bersifat
             * country-issued.
             */
            $table->char('issuing_country_code', 2);

            /*
             * Ciphertext hasil application encryption.
             *
             * TEXT digunakan karena ciphertext biasanya
             * lebih panjang daripada original identifier.
             */
            $table->text('encrypted_value');

            /*
             * Hex HMAC-SHA256 = 64 characters.
             *
             * Digunakan untuk duplicate detection/exact lookup
             * tanpa menyimpan raw identifier.
             */
            $table->char('value_fingerprint', 64);

            $table->string('issuer', 255)->nullable();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->string('status', 20)
                ->default('ACTIVE');

            $table->timestamps();

            /*
             * Legal identifier yang sama dari issuing country
             * yang sama tidak boleh dimiliki dua Person.
             */
            $table->unique(
                [
                    'type',
                    'issuing_country_code',
                    'value_fingerprint',
                ],
                'person_identifiers_identity_unique'
            );

            $table->index('person_id');

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_identifiers');
    }
};
