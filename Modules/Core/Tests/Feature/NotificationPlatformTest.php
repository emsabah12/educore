<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\Request;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;

final class NotificationPlatformTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        // 1. Seed Master Tenant demi integritas basis data relasional
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Notif Pusat',
            'subdomain' => 'testnotif',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Registrasi paksa jika provider belum ter-boot otomatis di test environment CLI
        if (class_exists(\Modules\Core\Providers\RouteServiceProvider::class)) {
            $this->app->register(\Modules\Core\Providers\RouteServiceProvider::class);
        }
    }

    public function test_controller_can_accept_payload_and_push_job_into_queue_correctly(): void
    {
        // 1. Kunci Laravel Bus Engine agar tidak menulis ke tabel jobs fisik database
        Bus::fake();

        // 2. STRATEGI PERSISTENSI GLOBAL: Ikat callback rebinding pada container 'request'
        // Ini memastikan objek request baru yang dibuat oleh internal framework tetap membawa context tenant
        $this->app->rebinding('request', function ($app, $request) {
            if ($request instanceof Request) {
                $request->attributes->set('authenticated_tenant_id', $this->tenantId);
                $request->attributes->set('authenticated_user_id', null);
            }
        });

        // Terapkan juga pada request saat ini yang sedang aktif
        $currentRequest = $this->app['request'];
        if ($currentRequest) {
            $currentRequest->attributes->set('authenticated_tenant_id', $this->tenantId);
        }

        $payload = [
            'recipient' => '089987654321',
            'body' => 'Pengumuman libur semester madrasah.',
            'options' => ['title' => 'Info Libur']
        ];

        // 3. Eksekusi Request HTTP POST
        $url = '/api/v1/core/notifications/dispatch';
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->json('POST', $url, $payload);

        // Debugging fail-safe print jika masih terhambat proteksi keamanan 401
        if ($response->getStatusCode() !== 202) {
            dump('Status Code Received: ' . $response->getStatusCode());
            dump('Body Response:', $response->getContent());
        }

        // 4. ASSERTIONS VERIFICATION
        $response->assertStatus(202);
        $response->assertJsonPath('status', 'success');

        // Pastikan Bus memvalidasi bahwa Job Asinkronus berhasil didorong masuk antrean
        Bus::assertDispatched(SendAsynchronousNotificationJob::class);
    }
}
