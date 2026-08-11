<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Notification\Channels\WhatsAppNotificationChannel;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class RecordingWhatsAppGateway implements WhatsAppGatewayInterface
{
    public ?string $tenantId = null;

    public ?string $notificationId = null;

    public ?string $recipient = null;

    public ?string $body = null;

    public int $attempts = 0;

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * @var list<WhatsAppGatewayResult>
     */
    private array $results;

    public function __construct(
        WhatsAppGatewayResult ...$results,
    ) {
        if ($results === []) {
            throw new InvalidArgumentException(
                'At least one gateway result is required.',
            );
        }

        $this->results = array_values($results);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): WhatsAppGatewayResult {
        $this->tenantId = $tenantId;
        $this->notificationId = $notificationId;
        $this->recipient = $recipient;
        $this->body = $body;
        $this->options = $options;

        $resultIndex = min(
            $this->attempts,
            count($this->results) - 1,
        );

        $this->attempts++;

        return $this->results[$resultIndex];
    }
}

final class ThrowingWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): WhatsAppGatewayResult {
        throw new RuntimeException(
            'Provider secret must not be persisted.',
        );
    }
}

final class WhatsAppNotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_gateway_result_marks_notification_as_sent(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' => 'provider-message-123',
                'provider_status' => 'queued',
            ]),
        );

        $channel = $this->channel($gateway);

        $result = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Successful WhatsApp notification.',
            options: [
                'title' => 'Success Test',
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            $notificationId,
            $result['log_id'],
        );
        $this->assertNull($result['error']);

        /*
         * Delivery data tetap tersedia secara transient bagi gateway.
         */
        $this->assertSame(
            '089987654321',
            $gateway->recipient,
        );
        $this->assertSame(
            'Successful WhatsApp notification.',
            $gateway->body,
        );
        $this->assertSame(
            'Success Test',
            $gateway->options['title'] ?? null,
        );

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'channel' => 'WHATSAPP',
                'status' => 'SENT',
                'failure_code' => null,
                'failure_reason' => null,
            ],
        );

        $this->assertSame(
            'provider-message-123',
            $this->storedProviderMetadata(
                $notificationId,
            )['provider_message_id'] ?? null,
        );
    }

    public function test_gateway_rejection_persists_canonical_failure_telemetry(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::failure(
                'provider_rejected',
                [
                    'provider_status' => 'rejected',
                ],
            ),
        );

        $result = $this->channel($gateway)->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Rejected WhatsApp notification.',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'WhatsApp provider rejected the delivery request.',
            $result['error'],
        );

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_code' => 'provider_rejected',
                'failure_reason' =>
                'WhatsApp provider rejected the delivery request.',
            ],
        );

        $this->assertSame(
            'rejected',
            $this->storedProviderMetadata(
                $notificationId,
            )['provider_status'] ?? null,
        );
    }

    public function test_default_gateway_fails_closed_with_canonical_failure_code(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $channel = $this->app->make(
            NotificationChannelInterface::class,
        );

        $result = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Unconfigured gateway notification.',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'WhatsApp gateway is not configured.',
            $result['error'],
        );

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_code' => 'gateway_not_configured',
                'failure_reason' =>
                'WhatsApp gateway is not configured.',
            ],
        );
    }

    public function test_unexpected_gateway_exception_persists_sanitized_failure(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $result = $this->channel(
            new ThrowingWhatsAppGateway(),
        )->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Unexpected gateway failure.',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'WhatsApp gateway communication failed.',
            $result['error'],
        );

        $row = DB::table('notification_attempts')
            ->where('id', $notificationId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('FAILED', $row->status);
        $this->assertSame(
            'gateway_communication_failed',
            $row->failure_code,
        );
        $this->assertSame(
            'WhatsApp gateway communication failed.',
            $row->failure_reason,
        );
        $this->assertNull($row->provider_metadata);
        $this->assertStringNotContainsString(
            'Provider secret',
            (string) $row->failure_reason,
        );
    }

    public function test_retry_reuses_same_notification_attempt(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::failure(
                'provider_rejected',
            ),
            WhatsAppGatewayResult::success([
                'provider_message_id' =>
                'provider-retry-success',
            ]),
        );

        $channel = $this->channel($gateway);

        $firstResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Retry notification.',
        );

        $this->assertFalse($firstResult['success']);

        $secondResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Retry notification.',
        );

        $this->assertTrue($secondResult['success']);
        $this->assertSame(2, $gateway->attempts);
        $this->assertSame(
            1,
            DB::table('notification_attempts')
                ->where('id', $notificationId)
                ->count(),
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

    public function test_already_sent_notification_is_not_sent_to_gateway_again(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' =>
                'provider-idempotent-success',
            ]),
        );

        $channel = $this->channel($gateway);

        $firstResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Idempotent notification.',
        );

        $secondResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Idempotent notification.',
        );

        $this->assertTrue($firstResult['success']);
        $this->assertTrue($secondResult['success']);
        $this->assertSame(1, $gateway->attempts);
        $this->assertSame(
            'provider-idempotent-success',
            $secondResult['metadata']['provider_message_id']
                ?? null,
        );
        $this->assertSame(
            1,
            DB::table('notification_attempts')
                ->where('id', $notificationId)
                ->count(),
        );
    }

    public function test_notification_identity_collision_across_tenants_is_rejected_before_gateway_execution(): void
    {
        $tenantAId = $this->createTenant();
        $tenantBId = $this->createTenant();
        $notificationId = UuidV7::generate();

        DB::table('notification_attempts')->insert([
            'id' => $notificationId,
            'tenant_id' => $tenantAId,
            'channel' => 'WHATSAPP',
            'status' => 'PENDING',
            'failure_code' => null,
            'failure_reason' => null,
            'provider_metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' => 'must-not-be-used',
            ]),
        );

        try {
            $this->channel($gateway)->send(
                tenantId: $tenantBId,
                notificationId: $notificationId,
                recipient: '082222222222',
                body: 'Tenant B must not reuse tenant A notification ID.',
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

        $this->assertSame(0, $gateway->attempts);

        $this->assertDatabaseHas(
            'notification_attempts',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantAId,
                'channel' => 'WHATSAPP',
                'status' => 'PENDING',
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

    private function channel(
        WhatsAppGatewayInterface $gateway,
    ): WhatsAppNotificationChannel {
        return new WhatsAppNotificationChannel(
            gateway: $gateway,
            attemptStore: $this->app->make(
                NotificationAttemptStoreInterface::class,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedProviderMetadata(
        string $notificationId,
    ): array {
        $providerMetadata = DB::table('notification_attempts')
            ->where('id', $notificationId)
            ->value('provider_metadata');

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
                'WhatsApp Channel Tenant %s',
                substr($tenantId, 0, 8),
            ),
            'subdomain' => sprintf(
                'wa-channel-%s',
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
