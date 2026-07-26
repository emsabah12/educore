<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;

final class NotificationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        /*
        |--------------------------------------------------------------------------
        | Seed Tenant
        |--------------------------------------------------------------------------
        |
        | Tenant harus tersedia agar context yang dikirim oleh test
        | merepresentasikan tenant yang valid di database.
        |
        */

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
        /*
        |--------------------------------------------------------------------------
        | 1. Fake Queue
        |--------------------------------------------------------------------------
        |
        | Job tidak benar-benar dikirim ke queue.
        | Laravel hanya mencatat bahwa Job telah di-dispatch.
        |
        */

        Bus::fake();

        /*
        |--------------------------------------------------------------------------
        | 2. Request Payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'recipient' => '089987654321',
            'body' => 'Pengumuman libur semester madrasah.',
            'options' => [
                'title' => 'Info Libur',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | 3. Execute HTTP Request
        |--------------------------------------------------------------------------
        |
        | InjectTenantContext membaca tenant context dari header
        | X-Tenant-UUID.
        |
        | User UUID sengaja dikosongkan karena endpoint notification
        | saat ini tidak mewajibkan authenticated user.
        |
        */

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Tenant-UUID' => $this->tenantId,
            ])
            ->json(
                'POST',
                '/api/v1/core/notifications/dispatch',
                $payload
            );

        /*
        |--------------------------------------------------------------------------
        | 4. Assert HTTP Response
        |--------------------------------------------------------------------------
        |
        | HTTP 202 berarti request diterima dan job berhasil dijadwalkan.
        |
        */

        $response->assertStatus(202);

        $response->assertJsonPath(
            'status',
            'success'
        );

        /*
        |--------------------------------------------------------------------------
        | 5. Assert Job Dispatch
        |--------------------------------------------------------------------------
        |
        | Pastikan notification job benar-benar dikirim ke Bus.
        |
        */

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class
        );
    }

    public function test_dispatched_notification_job_preserves_tenant_context(): void
    {
        Bus::fake();

        $this->app->instance(
            'current_tenant_uuid',
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
                'X-Tenant-UUID' => $this->tenantId,
            ])
            ->withCookie(
                'educore_session',
                'test-session-bridge'
            )
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
