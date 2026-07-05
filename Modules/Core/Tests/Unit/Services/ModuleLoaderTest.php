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
    public function test_load_returns_module_registry(): void
    {
        $loader = $this->app->make(ModuleLoader::class);

        $registry = $loader->load([
            ModuleDefinitionBuilder::make()
                ->name('User')
                ->build(),
        ]);

        $this->assertInstanceOf(ModuleRegistry::class, $registry);
    }

    public function test_load_registers_all_module_definitions(): void
    {
        $loader = $this->app->make(ModuleLoader::class);

        $registry = $loader->load([
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
        $loader = $this->app->make(ModuleLoader::class);

        $registry = $loader->load($this->definitions());

        $this->assertTrue($registry->has('User'));
        $this->assertTrue($registry->has('Academic'));
    }

    public function test_load_throws_exception_when_duplicate_module_is_loaded(): void
    {
        $loader = $this->app->make(ModuleLoader::class);

        $this->expectException(ModuleAlreadyRegisteredException::class);

        $loader->load([
            ModuleDefinitionBuilder::make()->name('User')->build(),
            ModuleDefinitionBuilder::make()->name('User')->build(),
        ]);
    }

    /**
     * @return Generator<int, mixed>
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