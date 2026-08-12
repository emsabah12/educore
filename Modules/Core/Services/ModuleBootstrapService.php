<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Platform\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Platform\Dependency\DependencyResolver;
use Modules\Core\Platform\Module\Services\ModuleLoader;
use Modules\Core\Platform\Registry\ModuleRegistry;

final readonly class ModuleBootstrapService
{
    public function __construct(
        private ModuleDiscovery $discovery,
        private ModuleManifestLoader $manifestLoader,
        private ModuleManifestParser $parser,
        private ModuleDefinitionFactory $factory,
        private ModuleLoader $moduleloader,
        private DependencyResolver $dependencyResolver
    ) {}

    public function bootstrap(string $modulesPath): ModuleRegistry
    {
        $definitions = [];

        foreach ($this->discovery->discover($modulesPath) as $manifestFile) {
            $contents = $this->manifestLoader->load($manifestFile);

            $manifest = $this->parser->parse($contents);

            $definitions[] = $this->factory->make($manifest);
        }

        $orderedDefinitions = $this->dependencyResolver->resolve($definitions);

        return $this->moduleloader->load($orderedDefinitions);
    }
}
