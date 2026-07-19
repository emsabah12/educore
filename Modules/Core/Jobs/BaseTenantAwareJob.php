<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class BaseTenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Konteks ID Penyewa (Tenant) yang harus dipertahankan sepanjang siklus hidup job.
     */
    protected string $tenantId;

    /**
     * ID Pengguna/Operator yang memicu eksekusi job.
     */
    protected ?string $operatorId;

    /**
     * Data muatan (payload) spesifik dari sub-class job.
     */
    protected array $payload;

    /**
     * Jumlah percobaan maksimal sebelum job dinyatakan gagal secara permanen.
     */
    public int $tries = 3;

    /**
     * Waktu jeda (dalam detik) sebelum job dicoba kembali setelah mengalami kegagalan.
     */
    public int $backoff = 30;

    /**
     * Constructor untuk mengunci konteks telemetri multi-tenant.
     */
    public function __construct(string $tenantId, ?string $operatorId, array $payload = [])
    {
        $this->tenantId = $tenantId;
        $this->operatorId = $operatorId;
        $this->payload = $payload;
    }

    /**
     * Metode interseptor bawaan Laravel yang dieksekusi sesaat sebelum metode handle() berjalan.
     * Bertugas mengembalikan status Tenant Context ke dalam ruang memori worker thread.
     */
    public function middleware(): array
    {
        return [
            new class($this->tenantId) {
                private string $tenantId;

                public function __construct(string $tenantId)
                {
                    $this->tenantId = $tenantId;
                }

                public function handle(object $job, callable $next): mixed
                {
                    // Injeksikan kembali ke context runtime attributes agar kueri database 
                    // di dalam job otomatis terkunci pada tenant yang benar.
                    request()->attributes->set('authenticated_tenant_id', $this->tenantId);

                    return $next($job);
                }
            }
        ];
    }
}
