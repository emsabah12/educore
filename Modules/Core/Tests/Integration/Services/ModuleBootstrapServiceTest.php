<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Integration\Services;

use Modules\Core\Platform\Discovery\ModuleDiscovery;
use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestLoader;
use Modules\Core\Manifest\ModuleManifestParser;
use Modules\Core\Manifest\ModuleManifestValidator;
use Modules\Core\Platform\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleBootstrapService;
use Modules\Core\Platform\Module\Services\ModuleLoader;
use Modules\Core\Platform\Dependency\DependencyResolver;
use Modules\Core\Services\EventDiscoveryService;
use Modules\Core\Platform\Module\Events\ModuleEventRegistry;
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
        $dependencyResolver = new \Modules\Core\Platform\Dependency\DependencyResolver();
        $eventRegistry = new ModuleEventRegistry();
        $eventDiscoveryService = new EventDiscoveryService($eventRegistry);

        // TAMBAHKAN INI:
        $eventRegistry = new \Modules\Core\Platform\Module\Events\ModuleEventRegistry();
        $eventDiscoveryService = new \Modules\Core\Services\EventDiscoveryService($eventRegistry);

        // 4. DIKOREKSI: Urutan parameter disesuaikan dengan baris ke-15 ModuleBootstrapService.php
        $this->bootstrapService = new ModuleBootstrapService(
            $discovery,       // Argumen #1
            $manifestLoader,  // Argumen #2
            $parser,          // Argumen #3 (Wajib ModuleManifestParser)
            $factory,         // Argumen #4 (Wajib ModuleDefinitionFactory)
            $moduleLoader,    // Argumen #5 (Wajib ModuleLoader)
            $dependencyResolver,  // Argumen #6 (Wajib DependencyResolver)
            $eventDiscoveryService  // Argumen #7 (Wajib EventDiscoveryService)
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

    /**
     * Hancurkan seluruh folder temporer OS untuk mengamankan storage piringan server.
     */
    protected function tearDown(): void
    {
        $this->filesystem->cleanup();
        parent::tearDown();
    }
}
