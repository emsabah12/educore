<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            /*
             * Append-only audit event identity.
             *
             * UUIDv7 dihasilkan application layer.
             */
            $table->uuid('id')->primary();

            /*
             * Optional tenant context.
             *
             * NULL valid untuk event global/platform,
             * pre-tenant authentication, atau setelah
             * referenced Tenant di-hard-delete.
             */
            $table->uuid('tenant_id')->nullable();

            /*
             * Authenticated account yang melakukan aksi.
             *
             * NULL valid untuk:
             * - unauthenticated event
             * - system operation
             * - deleted actor account
             */
            $table->uuid('actor_user_id')->nullable();

            /*
             * Stable machine-readable event identifier.
             *
             * Contoh:
             * auth.login_failed
             * tenant.created
             * queue.job.failed_permanently
             */
            $table->string('event_type', 100);

            /*
             * Human-readable summary.
             *
             * Tidak boleh menjadi tempat menyimpan secret
             * atau raw sensitive Person information.
             */
            $table->text('description');

            /*
             * Intentional structured metadata.
             *
             * Bukan raw request payload.
             */
            $table->jsonb('metadata')->nullable();

            /*
             * Optional technical request context.
             */
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            /*
             * Audit records immutable.
             */
            $table->timestampTz('created_at')
                ->useCurrent();

            /*
             * Baseline lookup indexes.
             */
            $table->index('tenant_id');
            $table->index('actor_user_id');
            $table->index('event_type');
            $table->index('created_at');

            /*
             * Audit history survives hard deletion
             * of referenced operational entities.
             */
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
