<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Platform\Module\Events\ModuleEventRegistry;
use ReflectionClass;
use ReflectionMethod;

final readonly class EventDiscoveryService
{
    public function __construct(
        private ModuleEventRegistry $eventRegistry
    ) {}

    /**
     * Memindai direktori listener dari suatu modul tertentu untuk meregistrasikan event-listener map.
     *
     * @param string $moduleName Nama Modul (e.g., 'Academic')
     * @param string $directory Jalur fisik folder (e.g., '/app/Modules/Academic/Listeners')
     * @param string $namespace Prefix namespace modul (e.g., 'Modules\Academic\Listeners')
     */
    public function discoverFrom(string $moduleName, string $directory, string $namespace): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }

            $className = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);

            if (!class_exists($className)) {
                continue;
            }

            $this->processClass($className);
        }
    }

    /**
     * Membedah method 'handle' suatu kelas menggunakan PHP Reflection untuk mengambil tipe parameter Event.
     */
    private function processClass(string $listenerClass): void
    {
        $reflection = new ReflectionClass($listenerClass);

        if (!$reflection->hasMethod('handle')) {
            return;
        }

        $method = $reflection->getMethod('handle');
        $parameters = $method->getParameters();

        if (empty($parameters)) {
            return;
        }

        // Ambil parameter pertama dari method handle()
        $firstParameter = $parameters[0];
        $type = $firstParameter->getType();

        if ($type === null || $type->isBuiltin()) {
            return;
        }

        // Dapatkan nama kelas Event dari type-hint parameter (FQCN)
        $eventClass = $firstParameter->getType()->getName();

        // Daftarkan relasi penemuan ini ke dalam Central Event Registry
        $this->eventRegistry->register($eventClass, $listenerClass);
    }
}
