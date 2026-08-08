<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_store_creates_pending_notification_attempt(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: 'Persistence Test',
            body: 'Notification persistence test.',
        );

        $this->assertFalse(
            $attempt->alreadySent,
        );

        $this->assertSame(
            [],
            $attempt->metadata,
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'channel' => 'WHATSAPP',
                'status' => 'PENDING',
            ],
        );
    }

    public function test_store_reuses_failed_attempt_without_creating_duplicate_row(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: null,
            body: 'Retry persistence test.',
        );

        $this->store->markFailed(
            tenantId: $tenantId,
            notificationId: $notificationId,
            failureReason: 'Provider rejected.',
            metadata: [
                'provider_status' => 'rejected',
            ],
        );

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: null,
            body: 'Retry persistence test.',
        );

        $this->assertFalse(
            $attempt->alreadySent,
        );

        $this->assertSame(
            1,
            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->count(),
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'PENDING',
                'failure_reason' => null,
            ],
        );
    }

    public function test_store_returns_cached_sent_attempt(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: null,
            body: 'Already sent test.',
        );

        $this->store->markSent(
            tenantId: $tenantId,
            notificationId: $notificationId,
            metadata: [
                'provider_message_id' => 'provider-123',
            ],
        );

        $attempt = $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: null,
            body: 'Already sent test.',
        );

        $this->assertTrue(
            $attempt->alreadySent,
        );

        $this->assertSame(
            'provider-123',
            $attempt->metadata['provider_message_id'] ?? null,
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'SENT',
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
            userId: null,
            recipient: '081111111111',
            channel: 'WHATSAPP',
            title: null,
            body: 'Tenant A notification.',
        );

        try {
            $this->store->prepareAttempt(
                tenantId: $tenantBId,
                notificationId: $notificationId,
                userId: null,
                recipient: '082222222222',
                channel: 'WHATSAPP',
                title: null,
                body: 'Tenant B notification.',
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
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantAId,
                'recipient' => '081111111111',
            ],
        );

        $this->assertDatabaseMissing(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantBId,
            ],
        );

        $this->assertSame(
            1,
            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->count(),
        );
    }

    public function test_store_marks_attempt_as_failed(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $this->store->prepareAttempt(
            tenantId: $tenantId,
            notificationId: $notificationId,
            userId: null,
            recipient: '089987654321',
            channel: 'WHATSAPP',
            title: null,
            body: 'Failed attempt.',
        );

        $this->store->markFailed(
            tenantId: $tenantId,
            notificationId: $notificationId,
            failureReason: 'Provider rejected.',
            metadata: [
                'provider_status' => 'rejected',
            ],
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_reason' => 'Provider rejected.',
            ],
        );
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
