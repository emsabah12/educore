<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class NotificationAttemptStoreTest extends TestCase
{
    use RefreshDatabase;

    private NotificationAttemptStoreInterface $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->app->make(
            NotificationAttemptStoreInterface::class,
        );
    }

    public function test_store_creates_canonical_pending_attempt_without_delivery_pii(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: ' whatsapp ',
        );

        $this->assertFalse($attempt->alreadySent);
        $this->assertSame([], $attempt->providerMetadata);

        $this->assertTrue(
            Schema::hasTable('notification_attempts'),
        );
        $this->assertFalse(
            Schema::hasTable('notification_logs'),
        );

        foreach ([
            'user_id',
            'recipient',
            'title',
            'body',
        ] as $legacyColumn) {
            $this->assertFalse(
                Schema::hasColumn(
                    'notification_attempts',
                    $legacyColumn,
                ),
            );
        }

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'channel' => 'WHATSAPP',
                'status' => 'PENDING',
                'failure_code' => null,
                'failure_reason' => null,
            ],
        );
    }

    public function test_store_reuses_failed_attempt_and_clears_previous_failure_telemetry(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        $this->store->markFailed(
            tenantId: $tenantId,
            notificationId: $notificationId,
            failureCode: 'provider_rejected',
            failureReason: 'Provider rejected.',
            providerMetadata: [
                'provider_status' => 'rejected',
            ],
        );

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        $this->assertFalse($attempt->alreadySent);

        $this->assertSame(
            1,
            DB::table('notification_attempts')
                ->where('id', $notificationId)
                ->count(),
        );

        $row = DB::table('notification_attempts')
            ->where('id', $notificationId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row->status);
        $this->assertNull($row->failure_code);
        $this->assertNull($row->failure_reason);
        $this->assertNull($row->provider_metadata);
    }

    public function test_store_returns_provider_metadata_for_cached_sent_attempt(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        $this->store->markSent(
            tenantId: $tenantId,
            notificationId: $notificationId,
            providerMetadata: [
                'provider_message_id' => 'provider-123',
            ],
        );

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        $this->assertTrue($attempt->alreadySent);
        $this->assertSame(
            'provider-123',
            $attempt->providerMetadata['provider_message_id']
                ?? null,
        );

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'SENT',
                'failure_code' => null,
                'failure_reason' => null,
            ],
        );
    }

    public function test_store_rejects_cross_tenant_notification_identity_collision(): void
    {
        $tenantAId = $this->createTenant();
        $tenantBId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantAId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        try {
            $this->store->prepareAttempt(
                tenantId: $tenantBId,
                notificationId: $notificationId,
                channel: 'WHATSAPP',
            );

            $this->fail(
                'Cross-tenant notification identity collision must be rejected.',
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Notification identity collision was detected.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantAId,
                'channel' => 'WHATSAPP',
            ],
        );

        $this->assertDatabaseMissing(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantBId,
            ],
        );

        $this->assertSame(
            1,
            DB::table('notification_attempts')
                ->where('id', $notificationId)
                ->count(),
        );
    }

    public function test_store_persists_failure_code_reason_and_provider_metadata(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            channel: 'WHATSAPP',
        );

        $this->store->markFailed(
            tenantId: $tenantId,
            notificationId: $notificationId,
            failureCode: 'provider_rejected',
            failureReason: 'Provider rejected.',
            providerMetadata: [
                'provider_status' => 'rejected',
            ],
        );

        $row = DB::table('notification_attempts')
            ->where('id', $notificationId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('FAILED', $row->status);
        $this->assertSame(
            'provider_rejected',
            $row->failure_code,
        );
        $this->assertSame(
            'Provider rejected.',
            $row->failure_reason,
        );
        $this->assertSame(
            'rejected',
            $this->decodeProviderMetadata(
                $row->provider_metadata,
            )['provider_status'] ?? null,
        );
    }

    public function test_store_rejects_non_uuid_v7_identifiers(): void
    {
        $tenantId = $this->createTenant();

        $this->expectException(
            InvalidArgumentException::class,
        );
        $this->expectExceptionMessage(
            'Notification identifier is invalid.',
        );

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: (string) Str::uuid(),
            channel: 'WHATSAPP',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProviderMetadata(
        mixed $providerMetadata,
    ): array {
        if (is_array($providerMetadata)) {
            return $providerMetadata;
        }

        if (! is_string($providerMetadata)) {
            return [];
        }

        $decoded = json_decode(
            $providerMetadata,
            true,
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => sprintf(
                'Notification Attempt Tenant %s',
                substr($tenantId, 0, 8),
            ),
            'subdomain' => sprintf(
                'notif-attempt-%s',
                substr(
                    str_replace('-', '', $tenantId),
                    0,
                    16,
                ),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }
}
