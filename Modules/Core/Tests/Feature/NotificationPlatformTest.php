<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Tests\TestCase;


final class NotificationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $userId;

    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        $this->userId = '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        $this->tokenManager = $this->app->make(
            TokenManagerInterface::class
        );

        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Notification Platform User',
            'email' => sprintf(
                'notification-platform-%s@educore.test',
                Str::lower(Str::random(10)),
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
    }

    public function test_controller_can_accept_payload_and_push_job_into_queue_correctly(): void
    {
        Bus::fake();

        $token = $this->tokenManager->issueToken(
            $this->userId,
            $this->tenantId
        );

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
            ->json(
                'POST',
                '/api/v1/core/notifications/dispatch',
                $payload
            );

        $response->assertStatus(202);

        $response->assertJsonPath(
            'status',
            'success'
        );

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class,
            function (SendAsynchronousNotificationJob $job): bool {
                return $job->getTenantId() === $this->tenantId;
            }
        );
    }

    public function test_dispatched_notification_job_preserves_tenant_context(): void
    {
        Bus::fake();

        $token = $this->tokenManager->issueToken(
            $this->userId,
            $this->tenantId
        );

        $payload = [
            'recipient' => '089987654321',
            'body' => 'Pengumuman tenant isolation test.',
            'options' => [
                'title' => 'Tenant Isolation Test',
            ],
        ];

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->json(
                'POST',
                '/api/v1/core/notifications/dispatch',
                $payload
            );

        $response->assertStatus(202);

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class,
            function (SendAsynchronousNotificationJob $job): bool {
                return $job->getTenantId() === $this->tenantId;
            }
        );
    }
}
