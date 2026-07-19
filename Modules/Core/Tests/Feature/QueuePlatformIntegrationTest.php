<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Jobs\BaseTenantAwareJob;
use Illuminate\Queue\Events\JobFailed;
use Modules\Core\Listeners\QueueWatchdogListener;
use RuntimeException;

final class MockCorruptedTenantJob extends BaseTenantAwareJob
{
    public function handle(): void
    {
        throw new RuntimeException('Simulasi kegagalan interseptasi antrean platform.');
    }
}

final class QueuePlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';
        $this->operatorId = '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        // 1. Seed Master Tenant
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Pusat Antrean',
            'subdomain' => 'queue-test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 2. Seed User Operator
        DB::table('users')->insert([
            'id' => $this->operatorId,
            'name' => 'System Operator Test',
            'email' => 'operator.queue@educore.id',
            'password' => bcrypt('secret-pass'),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function test_watchdog_captures_failed_job_and_persists_telemetry_log(): void
    {
        $payloadData = ['event_type' => 'sync_data_calas', 'target_batch' => 12];
        $job = new MockCorruptedTenantJob($this->tenantId, $this->operatorId, $payloadData);

        $exception = new RuntimeException('Simulasi kegagalan interseptasi antrean platform.');

        $mockQueueJob = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $mockQueueJob->method('getQueue')->willReturn('default');

        $event = new JobFailed('database', $mockQueueJob, $exception);
        $event->data = [
            'commandName' => MockCorruptedTenantJob::class,
            'command' => serialize($job)
        ];

        // Eksekusi pemanggilan hander listener secara langsung
        $listener = app(QueueWatchdogListener::class);
        $listener->handle($event);

        // PERBAIKAN: Ubah 'event' menjadi 'action' (atau sesuaikan jika nama kolom audit Anda berbeda, misal 'activity' atau 'name')
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantId,
            'user_id'   => $this->operatorId,
            'event_type'    => 'queue.job.failed_permanently' // <-- Diperbaiki di sini
        ]);

        // PERBAIKAN: Ubah 'event' menjadi 'action' pada query DB mentah
        $logRecord = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantId)
            ->where('event_type', 'queue.job.failed_permanently') // <-- Diperbaiki di sini
            ->first();

        // Pastikan data log ditemukan sebelum mencoba men-decode
        $this->assertNotNull($logRecord, 'Log audit gagal ditemukan di database.');

        $metadata = json_decode($logRecord->payload, true);

        $this->assertEquals(MockCorruptedTenantJob::class, $metadata['job_class']);
        $this->assertEquals(RuntimeException::class, $metadata['exception_class']);
        $this->assertEquals('sync_data_calas', $metadata['input_payload']['event_type']);
    }
}
