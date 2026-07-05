<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Builders;

use Modules\Core\Entities\ModuleDefinition;
use Modules\Core\Tests\Builders\ModuleDefinitionBuilder;
use PHPUnit\Framework\TestCase;

final class ModuleDefinitionBuilderTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $builder = ModuleDefinitionBuilder::make();

        $this->assertInstanceOf(
            ModuleDefinitionBuilder::class,
            $builder
        );
    }

    public function test_builds_default_module_definition(): void
    {
        $module = ModuleDefinitionBuilder::make()->build();

        $this->assertInstanceOf(
            ModuleDefinition::class,
            $module
        );

        $this->assertSame(1, $module->schema);
        $this->assertSame('core', $module->name);
        $this->assertSame('Core', $module->displayName);
        $this->assertSame('1.0.0', $module->version);
        $this->assertSame('Core Module', $module->description);

        $this->assertSame([], $module->providers);
        $this->assertSame([], $module->dependencies);
        $this->assertSame([], $module->metadata);
        $this->assertSame([], $module->extra);
    }

    public function test_overrides_name(): void
    {
        $module = ModuleDefinitionBuilder::make()
            ->name('auth')
            ->build();

        $this->assertSame('auth', $module->name);

        // nilai lain tetap default
        $this->assertSame('Core', $module->displayName);
        $this->assertSame('1.0.0', $module->version);
    }

    public function test_overrides_display_name(): void
    {
        $module = ModuleDefinitionBuilder::make()
            ->displayName('Authentication')
            ->build();

        $this->assertSame(
            'Authentication',
            $module->displayName
        );

        $this->assertSame('core', $module->name);
    }

    public function test_overrides_version(): void
    {
        $module = ModuleDefinitionBuilder::make()
            ->version('2.0.0')
            ->build();

        $this->assertSame(
            '2.0.0',
            $module->version
        );
    }

    public function test_overrides_description(): void
    {
        $module = ModuleDefinitionBuilder::make()
            ->description('Authentication Module')
            ->build();

        $this->assertSame(
            'Authentication Module',
            $module->description
        );
    }

    public function test_overrides_collection_properties(): void
    {
        $module = ModuleDefinitionBuilder::make()
            ->providers([
                App\Providers\AuthServiceProvider::class,
            ])
            ->dependencies([
                'core',
            ])
            ->metadata([
                'author' => 'EduCore',
            ])
            ->extra([
                'priority' => 10,
            ])
            ->build();

        $this->assertSame(
            [App\Providers\AuthServiceProvider::class],
            $module->providers
        );

        $this->assertSame(
            ['core'],
            $module->dependencies
        );

        $this->assertSame(
            ['author' => 'EduCore'],
            $module->metadata
        );

        $this->assertSame(
            ['priority' => 10],
            $module->extra
        );
    }

    public function test_builder_is_immutable(): void
    {
        $builder = ModuleDefinitionBuilder::make();

        $core = $builder->build();

        $auth = $builder
            ->name('auth')
            ->build();

        $this->assertSame(
            'core',
            $core->name
        );

        $this->assertSame(
            'auth',
            $auth->name
        );

        $this->assertNotSame(
            $core->name,
            $auth->name
        );
    }
}