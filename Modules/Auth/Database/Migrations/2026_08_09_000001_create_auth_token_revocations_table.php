<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'auth_token_revocations',
            function (Blueprint $table): void {
                /*
                 * SHA-256 hexadecimal fingerprint = 64 characters.
                 *
                 * Fingerprint menjadi natural identifier karena satu
                 * bearer token hanya membutuhkan satu revocation row.
                 */
                $table
                    ->string('token_hash', 64)
                    ->primary();

                /*
                 * Unix timestamp token expiry.
                 *
                 * Disimpan dalam format yang sama dengan claim
                 * expires_at sehingga tidak ada timezone conversion
                 * tersembunyi.
                 */
                $table
                    ->unsignedBigInteger('expires_at')
                    ->index();

                $table->timestamp('revoked_at');

                $table->timestamps();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'auth_token_revocations',
        );
    }
};
