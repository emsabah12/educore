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
            'notification_attempts',
            function (Blueprint $table): void {
                /*
                 * Logical notification identity.
                 *
                 * UUIDv7 dibuat sekali ketika notification job
                 * didispatch dan tetap stabil sepanjang retry.
                 */
                $table->uuid('id')->primary();

                /*
                 * Notification attempt merupakan tenant-owned
                 * operational persistence.
                 */
                $table->uuid('tenant_id');

                /*
                 * Delivery channel.
                 *
                 * Initial implementation:
                 * WHATSAPP.
                 *
                 * Tidak menggunakan database ENUM.
                 */
                $table->string('channel', 30);

                /*
                 * Mutable delivery lifecycle:
                 * PENDING / SENT / FAILED.
                 */
                $table->string('status', 20)
                    ->default('PENDING');

                /*
                 * Stable machine-readable provider/application
                 * failure identifier.
                 */
                $table->string(
                    'failure_code',
                    64,
                )->nullable();

                /*
                 * Sanitized human-readable failure explanation.
                 *
                 * Tidak menyimpan raw provider exception/response.
                 */
                $table->text('failure_reason')
                    ->nullable();

                /*
                 * Allowlisted provider telemetry only.
                 *
                 * Contoh aman:
                 * provider_message_id
                 * provider_status
                 *
                 * Bukan raw HTTP response, credential,
                 * recipient, atau message content.
                 */
                $table->jsonb('provider_metadata')
                    ->nullable();

                /*
                 * Mutable attempt membutuhkan created_at
                 * dan updated_at.
                 */
                $table->timestamps();

                /*
                 * Tenant-owned lookup / FK support.
                 */
                $table->index('tenant_id');

                /*
                 * Attempt merupakan operational child Tenant.
                 *
                 * Tenant normalnya soft-delete. Jika suatu Tenant
                 * benar-benar di-hard-purge, delivery attempts yang
                 * mengandung tenant operational telemetry ikut purge.
                 */
                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'notification_attempts',
        );
    }
};
