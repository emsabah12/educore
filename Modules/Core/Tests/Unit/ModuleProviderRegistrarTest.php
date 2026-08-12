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

    public function test_registers_providers_in_definition_order(): void
    {
        $registeredProviders = [];
        $application = $this->createMock(Application::class);

        $application
            ->method('register')
            ->willReturnCallback(
                static function (string $providerClass) use (&$registeredProviders): null {
                    $registeredProviders[] = $providerClass;

                    return null;
                }
            );

        $registrar = new ModuleProviderRegistrar($application);

        $registrar->register([
            new ModuleDefinition(
                schema: 1,
                name: 'HR',
                displayName: 'HR',
                version: '0.1.0',
                description: 'HR',
                providers: ['Modules\\HR\\Providers\\HRServiceProvider'],
                dependencies: ['core'],
            ),
            new ModuleDefinition(
                schema: 1,
                name: 'Academic',
                displayName: 'Academic',
                version: '0.1.0',
                description: 'Academic',
                providers: ['Modules\\Academic\\Providers\\AcademicServiceProvider'],
                dependencies: ['core', 'HR'],
            ),
        ]);

        $this->assertSame(
            [
                'Modules\\HR\\Providers\\HRServiceProvider',
                'Modules\\Academic\\Providers\\AcademicServiceProvider',
            ],
            $registeredProviders,
        );
    }

    public function test_provider_registration_failure_is_not_swallowed(): void
    {
        $application = $this->createMock(Application::class);

        $application
            ->method('register')
            ->willThrowException(new \RuntimeException('provider registration failed'));

        $registrar = new ModuleProviderRegistrar($application);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider registration failed');

        $registrar->register([
            new ModuleDefinition(
                schema: 1,
                name: 'Academic',
                displayName: 'Academic',
                version: '0.1.0',
                description: 'Academic',
                providers: ['Modules\\Academic\\Providers\\AcademicServiceProvider'],
                dependencies: ['core', 'HR'],
            ),
        ]);
    }
}
