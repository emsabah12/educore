<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Registry;

use Modules\Core\Entities\ModuleDefinition;
use Modules\Core\Exceptions\ModuleAlreadyRegisteredException;
use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Registry\ModuleRegistry;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleDefinitionBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $registry = new ModuleRegistry();

        $this->assertInstanceOf(
            ModuleRegistry::class,
            $registry
        );
    }

    public function test_registers_module(): void
    {
        $registry = new ModuleRegistry();

        $module = ModuleDefinitionBuilder::make()->build();

        $registry->register($module);

        $this->assertCount(
            1,
            $registry->all()
        );

        $this->assertSame(
            $module,
            $registry->get('core')
        );
    }

  public function test_prevents_duplicate_module_names(): void
  {
      $registry = new ModuleRegistry();

      $module = ModuleDefinitionBuilder::make()->build();

      $registry->register($module);

      $this->expectException(
          ModuleAlreadyRegisteredException::class
      );

      $this->expectExceptionMessage(
          "Module 'core' is already registered."
      );

      $registry->register($module);
  }

  public function test_has_registered_module(): void
  {
      $registry = new ModuleRegistry();

     $module = ModuleDefinitionBuilder::make()->build();

      $registry->register($module);

      $this->assertTrue(
          $registry->has('core')
      );

      $this->assertFalse(
          $registry->has('auth')
      );
  }

  public function test_gets_module_by_name(): void
  {
      $registry = new ModuleRegistry();

      $module = ModuleDefinitionBuilder::make()->build();

      $registry->register($module);

      $result = $registry->get('core');

      $this->assertSame($module, $result);
  }

  public function test_throws_when_module_not_found(): void
    {
        $registry = new ModuleRegistry();

        $this->expectException(
            ModuleNotFoundException::class
        );

        $this->expectExceptionMessage(
            "Module 'core' not found."
        );

        $registry->get('core');
    }

    public function test_returns_all_modules(): void
    {
        $registry = new ModuleRegistry();

        $core = ModuleDefinitionBuilder::make()
            ->name('core')
            ->build();

        $auth = ModuleDefinitionBuilder::make()
            ->name('auth')
            ->build();

        $registry->register($core);
        $registry->register($auth);

        $modules = $registry->all();

        $this->assertCount(2, $modules);

        $this->assertArrayHasKey('core', $modules);
        $this->assertArrayHasKey('auth', $modules);

        $this->assertSame($core, $modules['core']);
        $this->assertSame($auth, $modules['auth']);
    }

    public function test_returns_registry_count(): void
    {
        $registry = new ModuleRegistry();

        $this->assertSame(0, $registry->count());

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('core')
                ->build()
        );

        $this->assertSame(1, $registry->count());

        $registry->register(
            ModuleDefinitionBuilder::make()
                ->name('auth')
                ->build()
        );

        $this->assertSame(2, $registry->count());
    }
}