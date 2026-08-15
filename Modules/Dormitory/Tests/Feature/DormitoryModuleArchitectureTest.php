<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Feature;

use Modules\Core\Services\ModuleRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class DormitoryModuleArchitectureTest extends TestCase
{
    public function test_dormitory_is_manifest_driven_and_depends_only_on_core(): void
    {
        /** @var ModuleRepository $repository */
        $repository = $this->app->make(ModuleRepository::class);
        $definition = $repository->find('Dormitory');

        $this->assertNotNull(
            $definition,
            'Dormitory module must be discoverable from Modules/Dormitory/module.yaml.',
        );

        $this->assertSame(['core'], $definition->dependencies);
        $this->assertSame(
            ['Modules\\Dormitory\\Providers\\DormitoryServiceProvider'],
            $definition->providers,
        );

        $staticProviders = require base_path('bootstrap/providers.php');

        foreach ($definition->providers as $providerClass) {
            $this->assertNotContains(
                $providerClass,
                $staticProviders,
                'Dormitory must be activated from its manifest, not statically bootstrapped.',
            );

            $this->assertTrue(
                $this->app->providerIsLoaded($providerClass),
                sprintf('Dormitory provider [%s] must be loaded from its manifest.', $providerClass),
            );
        }
    }

    public function test_core_production_code_does_not_depend_on_dormitory_namespace(): void
    {
        $corePath = base_path('Modules/Core');
        $forbiddenReferences = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($corePath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $file->getPathname());

            if (str_contains($normalizedPath, '/Modules/Core/Tests/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                $this->fail(sprintf('Unable to read Core source file [%s].', $normalizedPath));
            }

            if (str_contains($contents, 'Modules\\Dormitory\\')) {
                $forbiddenReferences[] = $normalizedPath;
            }
        }

        $this->assertSame(
            [],
            $forbiddenReferences,
            "Core production code must not depend on Dormitory:\n".implode("\n", $forbiddenReferences),
        );
    }
}
