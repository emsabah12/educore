<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Fake notification channel untuk mengamati context runtime job.
 */
final class RecordingNotificationChannel implements NotificationChannelInterface
{
    public ?string $observedTenantContextId = null;

    public ?string $tenantIdArgument = null;

    public ?string $notificationIdArgument = null;

    public ?string $recipient = null;

    public ?string $body = null;

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * @param array<string, mixed> $options
     *
     * @return array{
     *     success: bool,
     *     log_id: string,
     *     metadata: array<string, mixed>,
     *     error: null
     * }
     */
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): array {
        $this->observedTenantContextId =
            $this->tenantContext->getCurrentTenantId();

        $this->tenantIdArgument = $tenantId;
        $this->notificationIdArgument = $notificationId;
        $this->recipient = $recipient;
        $this->body = $body;
        $this->options = $options;

        return [
            'success' => true,
            'log_id' => $notificationId,
            'metadata' => [
                'provider' => 'recording-test-channel',
            ],
            'error' => null,
        ];
    }
}

final class NotificationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $userId;

    private string $membershipId;

    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        $this->userId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        $this->membershipId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b56';

        $this->tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Notification Platform User',
            'email' => sprintf(
                'notification-platform-%s@educore.test',
                Str::lower(
                    Str::random(10),
                ),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Notif Pusat',
            'subdomain' => 'testnotif',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipId,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,

            /*
     * Legacy schema compatibility only.
     * Authorization tidak membaca field role ini.
     */
            'role' => 'notification-user',

            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueAuthenticatedToken(): string
    {
        return $this->tokenManager->issueToken(
            $this->userId,
            $this->tenantId,
            [
                'membership_id' => $this->membershipId,
            ],
        );
    }

    public function test_controller_accepts_payload_and_dispatches_tenant_scoped_job(): void
    {
        Bus::fake();

        $token = $this->issueAuthenticatedToken();

        $payload = [
            'recipient' => '089987654321',
            'body' => 'Pengumuman libur semester madrasah.',
            'options' => [
                'title' => 'Info Libur',
            ],
        ];

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->postJson(
                '/api/v1/core/notifications/dispatch',
                $payload,
            );

        $response
            ->assertAccepted()
            ->assertJsonPath(
                'status',
                'success',
            );

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class,
            function (
                SendAsynchronousNotificationJob $job,
            ): bool {
                return $job->getTenantId()
                    === $this->tenantId;
            },
        );
    }

    public function test_notification_job_executes_channel_inside_restored_tenant_context(): void
    {
        $tenantContext = $this->app->make(
            TenantContextInterface::class,
        );

        $recordingChannel =
            new RecordingNotificationChannel(
                $tenantContext,
            );

        /*
         * Override binding production hanya untuk test ini.
         */
        $this->app->instance(
            NotificationChannelInterface::class,
            $recordingChannel,
        );

        $payload = [
            'recipient' => '089987654321',
            'body' => 'Pengumuman tenant isolation test.',
            'options' => [
                'title' => 'Tenant Isolation Test',
                'user_id' => $this->userId,
            ],
        ];

        $job = new SendAsynchronousNotificationJob(
            $this->tenantId,
            $this->userId,
            $payload,
        );

        $middlewares = $job->middleware();

        $this->assertCount(
            1,
            $middlewares,
        );

        $middlewares[0]->handle(
            $job,
            static function (
                object $queuedJob,
            ): void {
                if (
                    ! $queuedJob
                        instanceof SendAsynchronousNotificationJob
                ) {
                    throw new \RuntimeException(
                        'Unexpected queue job type.',
                    );
                }

                $queuedJob->handle();
            },
        );

        /*
         * Channel harus dieksekusi ketika TenantContext sudah aktif.
         */
        $this->assertSame(
            $this->tenantId,
            $recordingChannel->observedTenantContextId,
        );

        $this->assertSame(
            $this->tenantId,
            $recordingChannel->tenantIdArgument,
        );

        $this->assertSame(
            $payload['recipient'],
            $recordingChannel->recipient,
        );

        $this->assertSame(
            $payload['body'],
            $recordingChannel->body,
        );

        $this->assertSame(
            $payload['options'],
            $recordingChannel->options,
        );

        /*
         * RestoreTenantContext wajib membersihkan mutable state
         * setelah job selesai.
         */
        $this->assertNull(
            $tenantContext->getCurrentTenantId(),
        );

        $this->assertNull(
            $tenantContext->getCurrentTenant(),
        );

        $this->assertSame(
            $job->getNotificationId(),
            $recordingChannel->notificationIdArgument,
        );
    }

    public function test_audit_failure_does_not_change_accepted_response_or_duplicate_dispatch(): void
    {
        Bus::fake();

        $this->app->instance(
            AuditTrailServiceInterface::class,
            new class implements AuditTrailServiceInterface
            {
                /**
                 * @param array<string, mixed>|null $payload
                 */
                public function log(
                    string $eventType,
                    string $description,
                    ?string $tenantId = null,
                    ?string $userId = null,
                    ?array $payload = null,
                ): void {
                    throw new RuntimeException(
                        'Simulated notification audit failure.',
                    );
                }
            },
        );

        $token = $this->issueAuthenticatedToken();

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->postJson(
                '/api/v1/core/notifications/dispatch',
                [
                    'recipient' => '089987654321',
                    'body' => 'Notification must remain accepted.',
                    'options' => [
                        'title' => 'Audit Failure Test',
                    ],
                ],
            );

        $response
            ->assertAccepted()
            ->assertJsonPath(
                'status',
                'success',
            );

        Bus::assertDispatchedTimes(
            SendAsynchronousNotificationJob::class,
            1,
        );

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class,
            function (
                SendAsynchronousNotificationJob $job,
            ): bool {
                return $job->getTenantId() === $this->tenantId
                    && $job->getNotificationId() !== '';
            },
        );
    }

    public function test_notification_id_is_preserved_across_queue_serialization(): void
    {
        $job = new SendAsynchronousNotificationJob(
            tenantId: $this->tenantId,
            operatorId: $this->userId,
            payload: [
                'recipient' => '089987654321',
                'body' => 'Queue serialization notification.',
                'options' => [
                    'title' => 'Serialization Test',
                ],
            ],
        );

        $originalNotificationId =
            $job->getNotificationId();

        $serializedJob = serialize(
            $job,
        );

        $restoredJob = unserialize(
            $serializedJob,
            [
                'allowed_classes' => true,
            ],
        );

        $this->assertInstanceOf(
            SendAsynchronousNotificationJob::class,
            $restoredJob,
        );

        $this->assertSame(
            $originalNotificationId,
            $restoredJob->getNotificationId(),
        );

        $this->assertSame(
            $this->tenantId,
            $restoredJob->getTenantId(),
        );
    }
}
