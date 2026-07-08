<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Tests\Builders\ModuleDefinitionBuilder;
use PHPUnit\Framework\TestCase;

final class ModuleRepositoryTest extends TestCase
{
    public function test_returns_all_modules(): void
    {
        $registry = new ModuleRegistry();

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('User')
                ->build()
        );

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('Academic')
                ->build()
        );

        $repository = new ModuleRepository($registry);

        $modules = $repository->all();

        $this->assertCount(2, $modules);
    }

    public function test_finds_module_by_name(): void
    {
        $registry = new ModuleRegistry();

        $definition = ModuleDefinitionBuilder::make()
            ->name('Core')
            ->build();

        $registry->register($definition);

        $repository = new ModuleRepository($registry);

        $module = $repository->find('Core');

        $this->assertSame($definition, $module);
    }

    public function test_checks_module_exists(): void
    {
        $registry = new ModuleRegistry();

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('Core')
                ->build()
        );

        $repository = new ModuleRepository($registry);

        $this->assertTrue(
            $repository->has('Core')
        );

        $this->assertFalse(
            $repository->has('Academic')
        );
    }

    public function test_returns_module_count(): void
    {
        $registry = new ModuleRegistry();

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('Core')
                ->build()
        );

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('Academic')
                ->build()
        );

        $repository = new ModuleRepository($registry);

        $this->assertSame(
            2,
            $repository->count()
        );
    }
}