<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Platform\Module\Services\ModuleProviderRegistrar;
use PHPUnit\Framework\TestCase;

final class ModuleProviderRegistrarTest extends TestCase
{
    public function test_registers_declared_non_core_provider_and_skips_core(): void
    {
        $application = $this->createMock(Application::class);

        $application
            ->expects($this->once())
            ->method('register')
            ->with('Modules\\Academic\\Providers\\AcademicServiceProvider');

        $registrar = new ModuleProviderRegistrar($application);

        $registrar->register([
            new ModuleDefinition(
                schema: 1,
                name: 'core',
                displayName: 'Core',
                version: '1.0.0',
                description: 'Core',
                providers: [
                    'Modules\\Core\\Providers\\CoreServiceProvider',
                ],
            ),
            new ModuleDefinition(
                schema: 1,
                name: 'Academic',
                displayName: 'Academic',
                version: '0.1.0',
                description: 'Academic',
                providers: [
                    'Modules\\Academic\\Providers\\AcademicServiceProvider',
                ],
                dependencies: ['core', 'HR'],
            ),
        ]);
    }
}
