<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Discovery;

use Modules\Core\Discovery\ModuleDiscovery;
use Modules\Core\Tests\Builders\ModuleBuilder;
use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;
use Modules\Core\Tests\Filesystem\TemporaryFilesystem;
use PHPUnit\Framework\TestCase;

final class ModuleDiscoveryTest extends TestCase
{
    private TemporaryFilesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new TemporaryFilesystem();
    }

    protected function tearDown(): void
    {
        $this->filesystem->cleanup();

        parent::tearDown();
    }

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(
            ModuleDiscovery::class,
            new ModuleDiscovery()
        );
    }

    public function test_returns_empty_array_when_modules_directory_does_not_exist(): void
    {
        $discovery = new ModuleDiscovery();

        $result = $discovery->discover(
            __DIR__ . '/non-existent-directory'
        );

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_modules_directory_is_empty(): void
    {
        $discovery = new ModuleDiscovery();

        $result = $discovery->discover(
            $this->filesystem->path()
        );

        $this->assertSame([], $result);
    }

    public function test_discovers_single_module_manifest(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('Core')
                    ->version('1.0.0')
            )
            ->addDirectory('Config')
            ->build();

        $this->filesystem->create($fixture);

        $discovery = new ModuleDiscovery();

        $result = $discovery->discover(
            $this->filesystem->path()
        );

        $this->assertCount(1, $result);

        $this->assertStringEndsWith(
            'module.yaml',
            $result[0]
        );
    }

    public function test_discovers_multiple_module_manifests(): void
        {
            $fixtures = [
                \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('Core')
                    )
                    ->build(),

                \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('HR')
                    )
                    ->build(),

                \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('PPDB')
                    )
                    ->build(),
            ];

            foreach ($fixtures as $fixture) {
                $this->filesystem->create($fixture);
            }

            $discovery = new ModuleDiscovery();

            $result = $discovery->discover(
                $this->filesystem->path()
            );

            $this->assertCount(3, $result);

            foreach ($result as $manifest) {
                $this->assertStringEndsWith(
                    'module.yaml',
                    $manifest
                );
            }
        }

        public function test_ignores_directories_without_manifest(): void
            {
                $fixture = \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('Core')
                    )
                    ->build();

                $this->filesystem->create($fixture);

                mkdir(
                    $this->filesystem->path()
                    . DIRECTORY_SEPARATOR
                    . 'Dummy'
                );

                mkdir(
                    $this->filesystem->path()
                    . DIRECTORY_SEPARATOR
                    . 'Empty'
                );

                $discovery = new ModuleDiscovery();

                $result = $discovery->discover(
                    $this->filesystem->path()
                );

                $this->assertCount(1, $result);

                $this->assertStringEndsWith(
                    'module.yaml',
                    $result[0]
                );

                $this->assertStringContainsString(
                    'Core',
                    $result[0]
                );
            }

           public function test_ignores_files_in_modules_root(): void
                {
                    $manifest = ManifestBuilder::make()->name('Core');

                    $fixture = ModuleFixtureBuilder::make()
                        ->manifest($manifest)
                        ->build();

                    $this->filesystem->create($fixture);

                    file_put_contents(
                        $this->filesystem->path()
                            . DIRECTORY_SEPARATOR
                            . 'README.md',
                        '# Modules'
                    );

                    file_put_contents(
                        $this->filesystem->path()
                            . DIRECTORY_SEPARATOR
                            . 'notes.txt',
                        'temporary'
                    );

                    $discovery = new ModuleDiscovery();

                    $result = $discovery->discover(
                        $this->filesystem->path()
                    );

                    $this->assertCount(1, $result);

                    $this->assertStringEndsWith(
                        'module.yaml',
                        $result[0]
                    );
                } 

        public function test_returns_manifests_in_deterministic_sorted_order(): void
            {
                $teacher = \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('Teacher')
                    )
                    ->build();

                $core = \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('Core')
                    )
                    ->build();

                $student = \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('Student')
                    )
                    ->build();

                $ppdb = \Modules\Core\Tests\Builders\ModuleFixtureBuilder::make()
                    ->manifest(
                        \Modules\Core\Tests\Builders\ManifestBuilder::make()
                            ->name('PPDB')
                    )
                    ->build();

                // Sengaja dibuat dalam urutan acak.
                $this->filesystem->create($teacher);
                $this->filesystem->create($core);
                $this->filesystem->create($student);
                $this->filesystem->create($ppdb);

                $discovery = new ModuleDiscovery();

                $result = $discovery->discover(
                    $this->filesystem->path()
                );

                $this->assertSame(
                    [
                        'Core',
                        'PPDB',
                        'Student',
                        'Teacher',
                    ],
                    array_map(
                        static fn (string $manifest): string => basename(dirname($manifest)),
                        $result
                    )
                );
            }
}