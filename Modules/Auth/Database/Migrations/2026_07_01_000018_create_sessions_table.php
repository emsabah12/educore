<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            /*
             * Framework-generated HTTP session identifier.
             */
            $table->string('id')->primary();

            /*
             * Optional authenticated account attached to
             * this Laravel HTTP session.
             *
             * Anonymous sessions legitimately have NULL user_id.
             */
            $table->uuid('user_id')
                ->nullable();

            /*
             * Technical request context maintained by Laravel's
             * DatabaseSessionHandler.
             */
            $table->string('ip_address', 45)
                ->nullable();

            $table->text('user_agent')
                ->nullable();

            /*
             * Serialized Laravel session state.
             *
             * This is framework state, not a canonical source
             * for Person, Tenant, Membership, Role, or Permission.
             */
            $table->longText('payload');

            /*
             * Unix timestamp used by Laravel session garbage
             * collection and idle-expiration handling.
             */
            $table->unsignedBigInteger('last_activity');

            $table->index('user_id');
            $table->index('last_activity');

            /*
             * Hard deletion of an account invalidates any
             * database-backed authenticated sessions belonging
             * to that account.
             */
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
