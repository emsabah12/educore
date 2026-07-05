<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Integration\Services;

use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;
use Modules\Core\Tests\Filesystem\TemporaryFilesystem;
use Tests\TestCase;

final class ModuleBootstrapServiceTest extends TestCase
{
    private TemporaryFilesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new TemporaryFilesystem();
    }

    protected function tearDown(): void
    {
        $this->filesystem->cleanup();

        parent::tearDown();
    }

    public function test_bootstrap_returns_registry(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('User')
            )
            ->build();

        $this->filesystem->create($fixture);

        $bootstrap = $this->app->make(ModuleBootstrapService::class);

        $registry = $bootstrap->bootstrap(
            $this->filesystem->path()
        );

        $this->assertInstanceOf(
            ModuleRegistry::class,
            $registry
        );
    }

    public function test_bootstrap_loads_all_discovered_modules(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()->name('User')
            )
            ->addDirectory('User')
            ->build();

        $fixture2 = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()->name('Auth')
            )
            ->addDirectory('Auth')
            ->build();

        $this->filesystem->create($fixture);
        $this->filesystem->create($fixture2);

        $bootstrap = $this->app->make(ModuleBootstrapService::class);

        $registry = $bootstrap->bootstrap(
            $this->filesystem->path()
        );

        $this->assertTrue($registry->has('User'));
        $this->assertTrue($registry->has('Auth'));
    }
}