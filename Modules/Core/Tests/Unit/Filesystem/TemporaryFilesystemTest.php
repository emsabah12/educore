<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Filesystem;

use Modules\Core\Tests\Builders\ManifestBuilder;
use Modules\Core\Tests\Builders\ModuleFixtureBuilder;
use Modules\Core\Tests\Filesystem\TemporaryFilesystem;
use PHPUnit\Framework\TestCase;

final class TemporaryFilesystemTest extends TestCase
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
            TemporaryFilesystem::class,
            $this->filesystem
        );

        $this->assertDirectoryExists(
            $this->filesystem->path()
        );
    }

    public function test_it_creates_temporary_workspace(): void
    {
        $this->assertDirectoryExists(
            $this->filesystem->path()
        );
    }

    public function test_it_creates_module_directory(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('PPDB')
            )
            ->build();

        $this->filesystem->create($fixture);

        $this->assertDirectoryExists(
            $this->filesystem->path()
            . DIRECTORY_SEPARATOR
            . $fixture->name
        );
    }

    public function test_it_creates_module_directories(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('PPDB')
            )
            ->addDirectory('Config')
            ->addDirectory('Providers')
            ->addDirectory('Services')
            ->build();

        $this->filesystem->create($fixture);

        $modulePath = $this->filesystem->path()
            . DIRECTORY_SEPARATOR
            . $fixture->name;

        $this->assertDirectoryExists(
            $modulePath . DIRECTORY_SEPARATOR . 'Config'
        );

        $this->assertDirectoryExists(
            $modulePath . DIRECTORY_SEPARATOR . 'Providers'
        );

        $this->assertDirectoryExists(
            $modulePath . DIRECTORY_SEPARATOR . 'Services'
        );
    }

    public function test_it_creates_module_files(): void
    {
        $fixture = ModuleFixtureBuilder::make()
            ->manifest(
                ManifestBuilder::make()
                    ->name('PPDB')
            )
            ->addDirectory('Config')
            ->addFile(
                'composer.json',
                "{}\n"
            )
            ->addFile(
                'routes/web.php',
                "<?php\n"
            )
            ->build();

        $this->filesystem->create($fixture);

        $modulePath = $this->filesystem->path()
            . DIRECTORY_SEPARATOR
            . $fixture->name;

        $this->assertFileExists(
            $modulePath . DIRECTORY_SEPARATOR . 'module.yaml'
        );

        $this->assertFileExists(
            $modulePath . DIRECTORY_SEPARATOR . 'composer.json'
        );

        $this->assertFileExists(
            $modulePath
            . DIRECTORY_SEPARATOR
            . 'routes'
            . DIRECTORY_SEPARATOR
            . 'web.php'
        );

        $this->assertSame(
            "{}\n",
            file_get_contents(
                $modulePath
                . DIRECTORY_SEPARATOR
                . 'composer.json'
            )
        );

        $this->assertSame(
            "<?php\n",
            file_get_contents(
                $modulePath
                . DIRECTORY_SEPARATOR
                . 'routes'
                . DIRECTORY_SEPARATOR
                . 'web.php'
            )
        );
    }

    public function test_it_cleans_up_workspace(): void
    {
        $workspace = dirname(
            $this->filesystem->path()
        );

        $this->assertDirectoryExists($workspace);

        $this->filesystem->cleanup();

        $this->assertDirectoryDoesNotExist($workspace);
    }
}