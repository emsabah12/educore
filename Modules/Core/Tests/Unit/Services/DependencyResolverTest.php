<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Platform\Dependency\DependencyResolver;
use Modules\Core\Exceptions\CircularDependencyException;
use Modules\Core\Exceptions\MissingModuleDependencyException;

final class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver();
    }

    public function test_resolves_independent_modules_in_any_order(): void
    {
        $modules = [
            'Core' => $this->createMockDefinition('Core', []),
            'Academic' => $this->createMockDefinition('Academic', []),
        ];

        $ordered = $this->resolver->resolve($modules);

        $this->assertCount(2, $ordered);
    }

    public function test_resolves_correct_boot_order_based_on_dependencies(): void
    {
        // Academic butuh UserManagement, UserManagement butuh Core.
        // Urutan booting yang sah wajib: Core -> UserManagement -> Academic
        $modules = [
            'Academic' => $this->createMockDefinition('Academic', ['UserManagement']),
            'Core' => $this->createMockDefinition('Core', []),
            'UserManagement' => $this->createMockDefinition('UserManagement', ['Core']),
        ];

        $ordered = $this->resolver->resolve($modules);

        $this->assertEquals('Core', $ordered[0]->name);
        $this->assertEquals('UserManagement', $ordered[1]->name);
        $this->assertEquals('Academic', $ordered[2]->name);
    }

    public function test_throws_exception_when_dependency_is_missing(): void
    {
        $modules = [
            'Academic' => $this->createMockDefinition('Academic', ['Finance']), // Finance tidak ada
            'Core' => $this->createMockDefinition('Core', []),
        ];

        $this->expectException(MissingModuleDependencyException::class);
        $this->expectExceptionMessage("Gagal memuat modul [Academic] karena modul prasyarat (dependency) [Finance] tidak ditemukan");

        $this->resolver->resolve($modules);
    }

    public function test_throws_exception_when_circular_dependency_is_detected(): void
    {
        // A butuh B, B butuh C, C butuh A (Siklus melingkar mati)
        $modules = [
            'ModulA' => $this->createMockDefinition('ModulA', ['ModulB']),
            'ModulB' => $this->createMockDefinition('ModulB', ['ModulC']),
            'ModulC' => $this->createMockDefinition('ModulC', ['ModulA']),
        ];

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage("Terdeteksi Circular Dependency");

        $this->resolver->resolve($modules);
    }

    /**
     * Helper untuk membuat objek tiruan ModuleDefinition secara cepat sesuai schema asli Anda.
     */
    private function createMockDefinition(string $name, array $dependencies): ModuleDefinition
    {
        return new ModuleDefinition(
            schema: 1,
            name: $name,
            displayName: $name . ' Display',
            version: 'version',
            description: $name . ' Description',
            providers: [],
            dependencies: $dependencies
        );
    }
}
