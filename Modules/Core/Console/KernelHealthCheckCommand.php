<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Services\Health\DatabaseHealthChecker;

final class KernelHealthCheckCommand extends Command
{
    /**
     * Nama dan sinatur dari command console.
     *
     * @var string
     */
    protected $signature = 'kernel:health-check';

    /**
     * Deskripsi singkat untuk menjelaskan fungsi command di daftar php artisan.
     *
     * @var string
     */
    protected $description = 'Melakukan inspeksi kesehatan infrastruktur inti Platform Kernel';

    /**
     * Eksekusi command console.
     */
    public function handle(DatabaseHealthChecker $checker): int
    {
        $this->components->info('Memulai inspeksi kesehatan infrastruktur EduCore...');

        // Memanggil internal service checker
        $result = $checker->check();

        if ($result['status'] === 'healthy') {
            $this->components->twoColumnDetail(
                '💾 Database PostgreSQL Connection',
                "<fg=green;options=bold>ONLINE</> ({$result['latency_ms']} ms)"
            );
            
            $this->components->info('Seluruh sistem infrastruktur inti dalam kondisi optimal.');
            return Command::SUCCESS;
        }

        // Jika status unhealthy
        $this->components->twoColumnDetail(
            'Database PostgreSQL Connection',
            '<fg=red;options=bold>CRITICAL / DOWN</>'
        );

        $this->components->error("Detail Kegagalan: {$result['error']}");
        
        return Command::FAILURE;
    }
}