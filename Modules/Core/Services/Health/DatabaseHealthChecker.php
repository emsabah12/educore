<?php

namespace Modules\Core\Services\Health;

use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

final readonly class DatabaseHealthChecker
{
    /**
     * Mengecek kesehatan koneksi database PostgreSQL default.
     * 
     * @return array{status: string, latency_ms: float, error: ?string}
     */
    public function check(): array
    {
        $startTime = microtime(true);

        try {
            // Mengeksekusi query paling ringan untuk memastikan database merespons
            DB::connection()->getPdo()->query('SELECT 1');
            
            $endTime = microtime(true);
            $latency = round(($endTime - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'error' => null
            ];
        } catch (Exception $e) {
            // Logging kegagalan infrastruktur penting untuk kebutuhan audit DevOps
            Log::emergency('Database health check failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'status' => 'unhealthy',
                'latency_ms' => 0.0,
                'error' => $e->getMessage()
            ];
        }
    }
}