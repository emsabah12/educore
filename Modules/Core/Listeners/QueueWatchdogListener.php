<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Modules\Core\Contracts\Auth\AuditTrailServiceInterface;
use Modules\Core\Jobs\BaseTenantAwareJob;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueueWatchdogListener
{
    private AuditTrailServiceInterface $auditTrail;

    /**
     * Dependency Injection.
     */
    public function __construct(AuditTrailServiceInterface $auditTrail)
    {
        $this->auditTrail = $auditTrail;
    }

    /**
     * Menangani interseptasi saat sebuah pekerjaan antrean gagal diproses secara permanen.
     */
    public function handle(JobFailed $event): void
    {
        try {
            $command = $event->data['command'] ?? null;

            if (! $command || ! is_string($command)) {
                return;
            }

            $jobInstance = unserialize($command);

            if ($jobInstance instanceof BaseTenantAwareJob) {
                $reflection = new \ReflectionClass($jobInstance);

                // 1. Ekstrak properti tenantId secara aman
                $tenantIdProp = $reflection->getProperty('tenantId');
                $tenantIdProp->setAccessible(true);
                $tenantId = (string) $tenantIdProp->getValue($jobInstance);

                // 2. Ekstrak properti operatorId secara aman
                $operatorIdProp = $reflection->getProperty('operatorId');
                $operatorIdProp->setAccessible(true);
                $operatorId = $operatorIdProp->getValue($jobInstance);

                // 3. Ekstrak properti payload secara aman
                $payloadProp = $reflection->getProperty('payload');
                $payloadProp->setAccessible(true);
                $rawPayload = $payloadProp->getValue($jobInstance);

                // PENGAMAN: Pastikan payload dinormalisasi menjadi array primitif murni yang bersih
                $cleanPayload = is_array($rawPayload) ? $rawPayload : (array) $rawPayload;

                $sanitizedOperatorId = (! empty($operatorId) && is_string($operatorId)) ? $operatorId : null;

                Log::channel('single')->warning(sprintf('Queue Job Failed Permanently: %s for Tenant: %s', $reflection->getShortName(), $tenantId));

                // 4. Kirim ke repositori Audit Trail Platform
                $this->auditTrail->log(
                    'queue.job.failed_permanently',
                    sprintf(
                        'Pekerjaan %s gagal secara permanen. Eror: %s',
                        $reflection->getShortName(),
                        substr($event->exception->getMessage(), 0, 150)
                    ),
                    $tenantId,
                    $sanitizedOperatorId,
                    [
                        'job_class' => $reflection->getName(),
                        'exception_class' => get_class($event->exception),
                        'input_payload' => $cleanPayload // Menggunakan array murni yang bebas dari PHP internal scope mapping
                    ]
                );
            }
        } catch (Throwable $e) {
            Log::channel('single')->error('Watchdog failed to persist log: ' . $e->getMessage());
        }
    }
}
