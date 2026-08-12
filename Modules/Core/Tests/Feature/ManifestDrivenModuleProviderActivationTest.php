<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Modules\Core\Services\ModuleRepository;
use Tests\TestCase;

final class ManifestDrivenModuleProviderActivationTest extends TestCase
{
    public function test_non_core_providers_are_loaded_from_manifests_only(): void
    {
        $staticProviders = require base_path('bootstrap/providers.php');

        /** @var ModuleRepository $repository */
        $repository = $this->app->make(ModuleRepository::class);

        foreach (['Academic', 'Auth', 'HR', 'PPDB', 'User'] as $moduleName) {
            $definition = $repository->find($moduleName);

            $this->assertNotNull($definition, sprintf('Module [%s] was not discovered.', $moduleName));
            $this->assertNotEmpty(
                $definition->providers,
                sprintf('Module [%s] must declare at least one provider.', $moduleName),
            );

            foreach ($definition->providers as $providerClass) {
                $this->assertNotContains(
                    $providerClass,
                    $staticProviders,
                    sprintf('Non-Core provider [%s] must not be statically bootstrapped.', $providerClass),
                );

                $this->assertTrue(
                    $this->app->providerIsLoaded($providerClass),
                    sprintf('Manifest provider [%s] was not loaded.', $providerClass),
                );
            }
        }
    }
}
