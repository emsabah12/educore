<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Platform\Registry\ModuleRegistry;
use Modules\Core\Platform\Module\Services\ModuleManager;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Tests\TestCase;

class ModuleManagerTest extends TestCase
{
    private ModuleRegistry $registry;
    private ModuleRepository $repository;
    private ModuleStateRepository $stateRepository;
    private ModuleManager $manager;
    private string $tempStatePath;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Inisialisasi Registry internal memori
        $this->registry = new ModuleRegistry();

        // 2. Bungkus Registry ke dalam Repository (Query Model)
        $this->repository = new ModuleRepository($this->registry);

        // 3. Gunakan berkas JSON sementara yang unik untuk mengisolasi pengujian I/O
        $this->tempStatePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'educore_test_modules_' . uniqid() . '.json';

        if (file_exists($this->tempStatePath)) {
            unlink($this->tempStatePath);
        }

        $this->stateRepository = new ModuleStateRepository($this->tempStatePath);

        // 4. Suntikkan komponen Services konkret ke dalam ModuleManager
        $this->manager = new ModuleManager($this->repository, $this->stateRepository);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempStatePath)) {
            unlink($this->tempStatePath);
        }

        parent::tearDown();
    }

    public function test_it_can_check_if_module_is_enabled(): void
    {
        // Menggunakan ->build() sesuai spesifikasi rancangan ManifestBuilder terbaru
        $manifestArray = ManifestBuilder::make()
            ->name('Academic')
            ->description('Academic Core Module')
            ->version('1.0.0')
            ->build();

        $definition = ModuleDefinition::fromArray($manifestArray);
        $this->registry->register($definition);

        $this->stateRepository->enable('Academic');

        $this->assertTrue($this->manager->isEnabled('Academic'));
    }

    public function test_it_can_enable_a_module_successfully(): void
    {
        $manifestArray = ManifestBuilder::make()
            ->name('PPDB')
            ->description('PPDB Module')
            ->version('1.0.0')
            ->build();

        $definition = ModuleDefinition::fromArray($manifestArray);
        $this->registry->register($definition);

        $this->manager->enable('PPDB');

        $this->assertTrue($this->stateRepository->isEnabled('PPDB'));
    }

    public function test_it_can_disable_a_module_successfully(): void
    {
        $manifestArray = ManifestBuilder::make()
            ->name('Core')
            ->description('Kernel Core Module')
            ->version('1.0.0')
            ->build();

        $definition = ModuleDefinition::fromArray($manifestArray);
        $this->registry->register($definition);

        $this->stateRepository->enable('Core');
        $this->assertTrue($this->stateRepository->isEnabled('Core'));

        $this->manager->disable('Core');

        $this->assertFalse($this->stateRepository->isEnabled('Core'));
    }

    public function test_it_throws_exception_if_module_not_found(): void
    {
        $this->expectException(ModuleNotFoundException::class);

        // Memastikan mekanisme Fail Fast berjalan saat modul tidak ada di registry
        $this->manager->isEnabled('NonExistentModule');
    }


    public function test_it_can_retrieve_all_enabled_module_definitions_safely(): void
    {
        // Setup mock/dummy ModuleDefinition jika diperlukan oleh test environment Anda
        // Panggil method eksposur baru melalui manager facade
        $enabledModules = $this->manager->getEnabledModules();

        $this->assertIsArray($enabledModules);
        // Pastikan item di dalamnya dibungkus objek ModuleDefinition, bukan array mentah
    }
}
