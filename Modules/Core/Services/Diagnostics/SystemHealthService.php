<?php

declare(strict_types=1);

namespace Modules\Core\Services\Diagnostics;

use Modules\Core\Platform\Health\Contracts\Diagnostics\HealthCheckerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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
            // GAP-024: endpoint ini publik (tidak ada middleware auth).
            // Detail asli (connection string, credential, path internal)
            // WAJIB hanya masuk log operasional — tidak pernah ke response
            // JSON yang bisa dibaca siapapun yang memanggil endpoint ini.
            Log::error('Health check: database connectivity failed.', [
                'exception' => $e,
            ]);

            return [
                'healthy' => false,
                'message' => 'Database connectivity check failed.'
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
            // GAP-024: sama seperti database — detail asli (path filesystem
            // internal, dsb.) hanya masuk log, tidak ke response publik.
            Log::error('Health check: storage read/write failed.', [
                'exception' => $e,
            ]);

            return [
                'healthy' => false,
                'message' => 'Storage connectivity check failed.'
            ];
        }
    }
}
