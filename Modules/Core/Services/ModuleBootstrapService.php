<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Entities\ModuleDefinition;
use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Services\DependencyResolver;
use Modules\Core\Services\EventDiscoveryService;
use Modules\Core\Registry\ModuleRegistry;

final readonly class ModuleBootstrapService
{
    public function __construct(
        private ModuleDiscovery $discovery,
        private ModuleManifestLoader $manifestLoader,
        private ModuleManifestParser $parser,
        private ModuleDefinitionFactory $factory,
        private ModuleLoader $moduleloader,
        private DependencyResolver $dependencyResolver,
        private EventDiscoveryService $eventDiscoveryService
    ) {
    }

    public function bootstrap(string $modulesPath): ModuleRegistry
    {
        $definitions = [];

        foreach ($this->discovery->discover($modulesPath) as $manifestFile) {
            $contents = $this->manifestLoader->load($manifestFile);

            $manifest = $this->parser->parse($contents);

            $definitions[] = $this->factory->make($manifest);
        }

        // 2. Topological Sort (Urutan Dependensi)
        $orderedDefinitions = $this->dependencyResolver->resolve($definitions);

        // 3. Auto-Discovery Event & Listeners untuk setiap modul aktif
        foreach ($orderedDefinitions as $definition) {
            // Tentukan jalur fisik folder Listeners secara dinamis (e.g., Modules/Academic/Listeners)
            $listenersPath = $modulesPath . DIRECTORY_SEPARATOR . $definition->name . DIRECTORY_SEPARATOR . 'Listeners';
            
            // Tentukan base namespace listener (e.g., Modules\Academic\Listeners)
            $listenerNamespace = 'Modules\\' . $definition->name . '\\Listeners';

            // Pemicu pemindaian otomatis
            $this->eventDiscoveryService->discoverFrom(
                $definition->name,
                $listenersPath,
                $listenerNamespace
            );
        }

        return $this->moduleloader->load($definitions);

        
    }
}