<?php

declare(strict_types=1);

namespace Modules\Core\Services\Diagnostics;

use Modules\Core\Contracts\Diagnostics\HealthCheckerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SystemHealthService implements HealthCheckerInterface
{
    public function checkSystem(): array
    {
        $components = [
            'database' => $this->checkDatabase(),
            'storage'  => $this->checkStorage(),
        ];

        $isHealthy = ! in_array(false, array_column($components, 'healthy'), true);

        return [
            'status' => $isHealthy ? 'UP' : 'DOWN',
            'timestamp' => now()->toIso8601String(),
            'components' => $components
        ];
    }

    private function checkDatabase(): array
    {
        try {
            DB::disconnect();

            // Pemicuan resolusi koneksi mentah
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $pdo->query('SELECT 1');

            return [
                'healthy' => true,
                'message' => 'PostgreSQL connection is responsive.'
            ];
        } catch (Throwable $e) {
            // Bersihkan pesan dari karakter biner Windows/PostgreSQL lokal yang merusak JSON encoder
            $cleanMessage = preg_replace('/[^\x20-\x7E]/', '', $e->getMessage());

            return [
                'healthy' => false,
                'message' => 'Database connection failed: ' . trim((string) $cleanMessage)
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $testFile = 'diagnostics/health_check_' . time() . '.txt';
            Storage::disk('local')->put($testFile, 'healthy');
            $content = Storage::disk('local')->get($testFile);
            Storage::disk('local')->delete($testFile);

            if ($content !== 'healthy') {
                throw new \RuntimeException('Storage read/write mismatch.');
            }

            return [
                'healthy' => true,
                'message' => 'Local storage disk is writable and readable.'
            ];
        } catch (Throwable $e) {
            $cleanMessage = preg_replace('/[^\x20-\x7E]/', '', $e->getMessage());

            return [
                'healthy' => false,
                'message' => 'Storage check failed: ' . trim((string) $cleanMessage)
            ];
        }
    }
}
