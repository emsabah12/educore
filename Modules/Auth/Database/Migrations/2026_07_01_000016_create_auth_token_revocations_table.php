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
                 * SHA-256 hexadecimal fingerprint of the exact
                 * bearer credential.
                 *
                 * Raw bearer tokens must never be persisted.
                 *
                 * One exact token only needs one revocation row,
                 * therefore the fingerprint itself is the
                 * canonical natural primary key.
                 */
                $table->char('token_hash', 64)
                    ->primary();

                /*
                 * Unix timestamp copied from the canonical
                 * token expires_at claim.
                 *
                 * A revocation row is no longer security-relevant
                 * after this timestamp and may be purged.
                 */
                $table->unsignedBigInteger('expires_at')
                    ->index();

                /*
                 * Time at which this credential was first revoked.
                 *
                 * Revocation records are immutable security facts,
                 * therefore no updated_at is needed.
                 */
                $table->timestampTz('revoked_at');
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
