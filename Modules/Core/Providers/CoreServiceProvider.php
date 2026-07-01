<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleLoader;
use Modules\Core\Services\ModuleStateRepository;
use Modules\Core\Services\ModuleManager;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleDiscovery::class);

        $this->app->singleton(ModuleManifestParser::class);

        $this->app->singleton(ModuleDefinitionFactory::class);

        $this->app->singleton(ModuleRegistry::class);

        $this->app->singleton(ModuleLoader::class);

        $this->app->singleton(ModuleStateRepository::class);
    
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(ModuleLoader $loader): void
    {
        $loader->load(base_path('Modules'));
    }
}