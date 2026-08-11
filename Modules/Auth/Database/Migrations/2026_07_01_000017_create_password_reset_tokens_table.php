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
            'password_reset_tokens',
            function (Blueprint $table): void {
                /*
                 * Account/login email at token issuance time.
                 *
                 * Laravel DatabaseTokenRepository uses this
                 * exact column as its canonical lookup key.
                 *
                 * PRIMARY KEY also enforces one active reset
                 * token record per account email.
                 */
                $table->string('email', 255)
                    ->primary();

                /*
                 * Hashed password-reset token.
                 *
                 * Laravel hashes the generated reset token before
                 * persistence. Raw reset tokens must never be
                 * persisted or logged.
                 */
                $table->string('token', 255);

                /*
                 * Used by Laravel for:
                 * - expiration
                 * - throttling
                 * - expired-token cleanup.
                 */
                $table->timestampTz('created_at');

                /*
                 * Laravel deleteExpired() performs a time-based
                 * cleanup query against created_at.
                 */
                $table->index('created_at');
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'password_reset_tokens',
        );
    }
};
