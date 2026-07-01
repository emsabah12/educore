<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Registry\ModuleRegistry;

final class ModuleLoader
{
    public function __construct(
        private readonly ModuleDiscovery $discovery,
        private readonly ModuleManifestParser $parser,
        private readonly ModuleDefinitionFactory $factory,
        private readonly ModuleRegistry $registry,
    ) {
    }

    public function load(string $modulesPath): ModuleRegistry
    {
        $manifestFiles = $this->discovery->discover($modulesPath);

        foreach ($manifestFiles as $manifestFile) {
            $manifest = $this->parser->parse($manifestFile);

            $module = $this->factory->make($manifest);

            $this->registry->register($module);
        }

        return $this->registry;
    }
}