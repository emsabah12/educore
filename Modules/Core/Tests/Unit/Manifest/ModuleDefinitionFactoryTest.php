<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Manifest;

use Modules\Core\Manifest\ModuleDefinitionFactory;
use Modules\Core\Manifest\ModuleManifestValidator;
use Modules\Core\Exceptions\InvalidModuleManifestException;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleDefinitionBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;

use PHPUnit\Framework\TestCase;

final class ModuleDefinitionFactoryTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $factory = new ModuleDefinitionFactory(
            new ModuleManifestValidator(),
        );

        $this->assertInstanceOf(
            ModuleDefinitionFactory::class,
            $factory
        );
    }

    public function test_creates_module_definition_from_valid_manifest(): void
    {
        $factory = new ModuleDefinitionFactory(
            new ModuleManifestValidator(),
        );

        $manifest = ManifestBuilder::make()
            ->name('core')
            ->displayName('Core')
            ->description('EduCore Platform Kernel')
            ->version('1.0.0')
            ->build();

        $definition = $factory->make($manifest);

        $this->assertInstanceOf(
            \Modules\Core\Platform\Module\Domain\ModuleDefinition::class,
            $definition
        );

        $this->assertSame(1, $definition->schema);
        $this->assertSame('core', $definition->name);
        $this->assertSame('1.0.0', $definition->version);
        $this->assertSame(
            'EduCore Platform Kernel',
            $definition->description
        );
    }

    public function test_preserves_all_manifest_values(): void
    {
        $factory = new ModuleDefinitionFactory(
            new ModuleManifestValidator(),
        );

        $manifest = ManifestBuilder::make()
            ->name('core')
            ->displayName('Core')
            ->build();

        $definition = $factory->make($manifest);

        $this->assertSame(
            $manifest['providers'],
            $definition->providers
        );

        $this->assertSame(
            $manifest['dependencies'],
            $definition->dependencies
        );

        $this->assertSame(
            $manifest['metadata'],
            $definition->metadata
        );

        $this->assertSame(
            $manifest['extra'],
            $definition->extra
        );
    }

    public function test_rejects_invalid_manifest(): void
    {
        $factory = new ModuleDefinitionFactory(
            new ModuleManifestValidator(),
        );

        $manifest = ManifestBuilder::make()
            ->name('core')
            ->displayName('Core')
            ->build();

        unset($manifest['name']);

        $this->expectException(
            InvalidModuleManifestException::class
        );

        $factory->make($manifest);
    }
}
