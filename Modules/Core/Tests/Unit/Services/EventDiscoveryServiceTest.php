<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Modules\Core\Registry\ModuleEventRegistry;
use Modules\Core\Services\EventDiscoveryService;
use Modules\Core\Tests\Filesystem\TemporaryFilesystem;

final class EventDiscoveryServiceTest extends TestCase
{
    private ModuleEventRegistry $eventRegistry;
    private EventDiscoveryService $discoveryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventRegistry = new ModuleEventRegistry();
        $this->discoveryService = new EventDiscoveryService($this->eventRegistry);
    }

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(EventDiscoveryService::class, $this->discoveryService);
    }

    public function test_discovers_listeners_from_directory_and_extracts_events(): void
    {
        // 1. Inisialisasi TemporaryFilesystem asli sesuai namespace & behavior konstruktor proyek Anda
        $filesystem = new TemporaryFilesystem();

        try {
            // 2. Buat path folder Listeners buatan di dalam temporary workspace
            $listenersPath = $filesystem->path() . DIRECTORY_SEPARATOR . 'Academic' . DIRECTORY_SEPARATOR . 'Listeners';
            mkdir($listenersPath, 0755, true);

            // 3. Tulis file kelas Listener riil ke area sandbox
            $listenerContent = <<<'PHP'
<?php
namespace Modules\Core\Tests\Unit\Services\Fixtures;

class SendWelcomeEmailFixture {
    public function handle(\Modules\PPDB\Events\StudentRegistered $event): void {
        // dummy logic
    }
}
PHP;
            file_put_contents($listenersPath . DIRECTORY_SEPARATOR . 'SendWelcomeEmailFixture.php', $listenerContent);

            // Muat berkas ke memori PHP Runtime agar bisa diinspeksi oleh ReflectionClass
            require_once $listenersPath . DIRECTORY_SEPARATOR . 'SendWelcomeEmailFixture.php';

            // 4. Jalankan pemindaian otomatis menggunakan EventDiscoveryService
            $this->discoveryService->discoverFrom(
                moduleName: 'Academic',
                directory: $listenersPath,
                namespace: 'Modules\Core\Tests\Unit\Services\Fixtures'
            );

            // 5. Validasi: Pastikan EventRegistry sukses merekam relasi pemetaan Event -> Listener
            $listeners = $this->eventRegistry->getListenersFor('Modules\PPDB\Events\StudentRegistered');
            
            $this->assertCount(1, $listeners);
            $this->assertEquals('Modules\Core\Tests\Unit\Services\Fixtures\SendWelcomeEmailFixture', $listeners[0]);

        } finally {
            // Pastikan cleanup() dipanggil dengan huruf kecil sesuai source code asli Anda
            $filesystem->cleanup();
        }
    }
}