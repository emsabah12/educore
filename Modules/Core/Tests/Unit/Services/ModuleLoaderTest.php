<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use Generator;
use Modules\Core\Exceptions\ModuleAlreadyRegisteredException;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleLoader;
use Modules\Core\Tests\Builders\ModuleDefinitionBuilder;
use Tests\TestCase;

final class ModuleLoaderTest extends TestCase
{
    private ModuleRegistry $registryStorage;
    private ModuleLoader $loader;

    /**
     * Set up lingkungan uji yang bersih sebelum setiap metode test dieksekusi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Putus hubungan dari IoC Container aplikasi riil demi mengamankan memori sasis
        $this->registryStorage = new ModuleRegistry();
        $this->loader = new ModuleLoader($this->registryStorage);
    }

    public function test_load_returns_module_registry(): void
    {
        $registry = $this->loader->load([
            ModuleDefinitionBuilder::make()
                ->name('User')
                ->build(),
        ]);

        $this->assertInstanceOf(ModuleRegistry::class, $registry);
    }

    public function test_load_registers_all_module_definitions(): void
    {
        $registry = $this->loader->load([
            ModuleDefinitionBuilder::make()->name('User')->build(),
            ModuleDefinitionBuilder::make()->name('Auth')->build(),
            ModuleDefinitionBuilder::make()->name('Academic')->build(),
        ]);

        $this->assertTrue($registry->has('User'));
        $this->assertTrue($registry->has('Auth'));
        $this->assertTrue($registry->has('Academic'));
    }

    public function test_load_accepts_iterable(): void
    {
        $registry = $this->loader->load($this->definitions());

        $this->assertTrue($registry->has('User'));
        $this->assertTrue($registry->has('Academic'));
    }

    public function test_load_throws_exception_when_duplicate_module_is_loaded(): void
    {
        $this->expectException(ModuleAlreadyRegisteredException::class);

        $this->loader->load([
            ModuleDefinitionBuilder::make()->name('User')->build(),
            ModuleDefinitionBuilder::make()->name('User')->build(),
        ]);
    }

    /**
     * Penyedia data taktis menggunakan struktur Generator PHP untuk efisiensi memori.
     * 
     * @return Generator<int, \Modules\Core\Entities\ModuleDefinition>
     */
    private function definitions(): Generator
    {
        yield ModuleDefinitionBuilder::make()
            ->name('User')
            ->build();

        yield ModuleDefinitionBuilder::make()
            ->name('Academic')
            ->build();
    }
}