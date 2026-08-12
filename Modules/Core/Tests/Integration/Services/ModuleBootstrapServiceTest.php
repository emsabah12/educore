<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Integration\Services;

use Modules\Core\Platform\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Manifest\ModuleManifestValidator;
use Modules\Core\Platform\Registry\ModuleRegistry;
use Modules\Core\Exceptions\CircularDependencyException;
use Modules\Core\Exceptions\MissingModuleDependencyException;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Platform\Module\Services\ModuleLoader;
use Modules\Core\Platform\Dependency\DependencyResolver;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;
use Modules\Core\Tests\Filesystem\TemporaryFilesystem;
use Tests\TestCase;

final class ModuleBootstrapServiceTest extends TestCase
{
    private TemporaryFilesystem $filesystem;
    private ModuleRegistry $registryStorage;
    private ModuleBootstrapService $bootstrapService;

    /**
     * Siapkan sasis lingkungan pengujian yang terisolasi dari IoC Container global.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Workspace filesystem virtual terisolasi
        $this->filesystem = new TemporaryFilesystem();

        // 2. Storage in-memory baru ("kertas putih" tanpa polusi state aplikasi)
        $this->registryStorage = new ModuleRegistry();

        // 3. Bangun objek-objek prasyarat secara mandiri
        $discovery = new ModuleDiscovery();
        $parser = new ModuleManifestParser();
        $validator = new ModuleManifestValidator();
        $factory = new ModuleDefinitionFactory($validator);

        $manifestLoader = new ModuleManifestLoader($parser, $validator, $factory);
        $moduleLoader = new ModuleLoader($this->registryStorage);
        $dependencyResolver = new DependencyResolver();

        $this->bootstrapService = new ModuleBootstrapService(
            $discovery,
            $manifestLoader,
            $parser,
            $factory,
            $moduleLoader,
            $dependencyResolver,
        );
    }

    /**
     * Memastikan proses bootstrap mengembalikan objek ModuleRegistry yang sah.
     */
    public function test_bootstrap_returns_registry(): void
    {
        $manifest = ManifestBuilder::make()->name('Academic');
        $fixture = ModuleFixtureBuilder::make()->manifest($manifest)->build();
        $this->filesystem->create($fixture);

        // Eksekusi pipeline bootstrap pada folder temporer terisolasi
        $registry = $this->bootstrapService->bootstrap($this->filesystem->path());

        $this->assertInstanceOf(ModuleRegistry::class, $registry);
        $this->assertSame($this->registryStorage, $registry);
    }

    /**
     * Memastikan seluruh modul yang terpindai di folder target sukses masuk ke registry memori.
     */
    public function test_bootstrap_loads_all_discovered_modules(): void
    {
        $academic = ModuleFixtureBuilder::make()->manifest(ManifestBuilder::make()->name('Academic'))->build();
        $ppdb = ModuleFixtureBuilder::make()->manifest(ManifestBuilder::make()->name('PPDB'))->build();

        $this->filesystem->create($academic);
        $this->filesystem->create($ppdb);

        $this->bootstrapService->bootstrap($this->filesystem->path());

        $this->assertTrue($this->registryStorage->has('Academic'));
        $this->assertTrue($this->registryStorage->has('PPDB'));
        $this->assertSame(2, $this->registryStorage->count());
    }

    public function test_bootstrap_registers_modules_in_dependency_order(): void
    {
        $academic = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('Academic')
                    ->dependencies(['HR'])
            )
            ->build();

        $core = ModuleFixtureBuilder::make()
            ->manifest(ManifestBuilder::make()->name('core'))
            ->build();

        $hr = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('HR')
                    ->dependencies(['core'])
            )
            ->build();

        // Create deliberately out of dependency order. Discovery order must not
        // become bootstrap/provider order.
        $this->filesystem->create($academic);
        $this->filesystem->create($core);
        $this->filesystem->create($hr);

        $registry = $this->bootstrapService->bootstrap($this->filesystem->path());

        $this->assertSame(
            ['core', 'HR', 'Academic'],
            array_keys($registry->all()),
        );
    }

    public function test_bootstrap_fails_fast_when_dependency_is_missing(): void
    {
        $academic = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('Academic')
                    ->dependencies(['HR'])
            )
            ->build();

        $this->filesystem->create($academic);

        $this->expectException(MissingModuleDependencyException::class);

        $this->bootstrapService->bootstrap($this->filesystem->path());
    }

    public function test_bootstrap_fails_fast_when_dependency_cycle_is_detected(): void
    {
        $moduleA = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('ModuleA')
                    ->dependencies(['ModuleB'])
            )
            ->build();

        $moduleB = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('ModuleB')
                    ->dependencies(['ModuleA'])
            )
            ->build();

        $this->filesystem->create($moduleA);
        $this->filesystem->create($moduleB);

        $this->expectException(CircularDependencyException::class);

        $this->bootstrapService->bootstrap($this->filesystem->path());
    }

    /**
     * Hancurkan seluruh folder temporer OS untuk mengamankan storage piringan server.
     */
    protected function tearDown(): void
    {
        $this->filesystem->cleanup();
        parent::tearDown();
    }
}
