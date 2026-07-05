<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Services\ModuleLoader;
use Modules\Core\Services\ModuleManager;
use Modules\Core\Services\ModuleStateRepository;
use Modules\Core\Support\Uuid\Contracts\UuidGeneratorInterface;
use Modules\Core\Support\Uuid\UuidGenerator;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
       $this->app->singleton(ModuleDiscovery::class);

        $this->app->singleton(ModuleManifestLoader::class);

        $this->app->singleton(ModuleManifestParser::class);

        $this->app->singleton(ModuleDefinitionFactory::class);

        $this->app->singleton(ModuleRegistry::class);

        $this->app->singleton(ModuleLoader::class);

        $this->app->singleton(ModuleStateRepository::class);

        $this->app->singleton(ModuleManager::class);

        $this->app->singleton(ModuleBootstrapService::class);
    }

    public function boot(ModuleBootstrapService $bootstrap): void
    {
        $bootstrap->bootstrap(
            base_path('Modules')
        );
    }
}