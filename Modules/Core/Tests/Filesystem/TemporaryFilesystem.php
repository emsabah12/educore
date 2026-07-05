<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Filesystem;

use FilesystemIterator;
use Modules\Core\Tests\Fixtures\ModuleFixture;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TemporaryFilesystem
{
    /**
     * Absolute path to the temporary workspace.
     */
    private string $workspace;

    /**
     * Absolute path to the temporary Modules directory.
     */
    private string $modulesPath;

    public function __construct()
    {
        $this->workspace = $this->createWorkspace();

        $this->modulesPath = $this->workspace
            . DIRECTORY_SEPARATOR
            . 'Modules';
    }

    /**
     * Create an entire module fixture.
     */
    public function create(ModuleFixture $fixture): void
    {
        $modulePath = $this->createModuleDirectory($fixture);

        $this->createDirectories($modulePath, $fixture);

        $this->createFiles($modulePath, $fixture);
    }

    /**
     * Return the Modules directory path.
     */
    public function path(): string
    {
        return $this->modulesPath;
    }

    /**
     * Remove the temporary workspace.
     */
    public function cleanup(): void
    {
        if (! isset($this->workspace) || ! is_dir($this->workspace)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->workspace,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->workspace);
    }

    /**
     * Create the temporary workspace.
     */
    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . $this->generateDirectoryName();

        $modulesPath = $workspace
            . DIRECTORY_SEPARATOR
            . 'Modules';

        if (! mkdir($modulesPath, 0777, true) && ! is_dir($modulesPath)) {
            throw new RuntimeException(
                sprintf(
                    'Unable to create temporary workspace [%s].',
                    $modulesPath
                )
            );
        }

        return $workspace;
    }

    /**
     * Generate a unique workspace directory name.
     */
    private function generateDirectoryName(): string
    {
        return sprintf(
            'educore-testing-%s-%s',
            date('Ymd-His'),
            bin2hex(random_bytes(3))
        );
    }

    /**
     * Create the module root directory.
     */
    private function createModuleDirectory(
        ModuleFixture $fixture
    ): string {
        $modulePath = $this->modulesPath
            . DIRECTORY_SEPARATOR
            . $fixture->name;

        if (! mkdir($modulePath, 0777, true) && ! is_dir($modulePath)) {
            throw new RuntimeException(
                sprintf(
                    'Unable to create module directory [%s].',
                    $modulePath
                )
            );
        }

        return $modulePath;
    }

    /**
     * Create module directories.
     */
    private function createDirectories(
        string $modulePath,
        ModuleFixture $fixture,
    ): void {
        foreach ($fixture->directories as $directory) {
            $path = $modulePath
                . DIRECTORY_SEPARATOR
                . $directory;

            if (! mkdir($path, 0777, true) && ! is_dir($path)) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to create directory [%s].',
                        $path
                    )
                );
            }
        }
    }

    /**
     * Create module files.
     */
    private function createFiles(
        string $modulePath,
        ModuleFixture $fixture,
    ): void {
        foreach ($fixture->files as $relativePath => $contents) {
            $path = $modulePath
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            $directory = dirname($path);

            if (
                ! is_dir($directory)
                && ! mkdir($directory, 0777, true)
                && ! is_dir($directory)
            ) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to create directory [%s].',
                        $directory
                    )
                );
            }

            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to create file [%s].',
                        $path
                    )
                );
            }
        }
    }
}