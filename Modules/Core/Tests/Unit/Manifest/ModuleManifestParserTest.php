<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Manifest;

use Modules\Core\Manifest\ModuleManifestParser;
use PHPUnit\Framework\TestCase;

final class ModuleManifestParserTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $parser = new ModuleManifestParser();

        $this->assertInstanceOf(
            ModuleManifestParser::class,
            $parser
        );
    }

    public function test_parses_valid_yaml_manifest(): void
        {
            $yaml = <<<'YAML'
        name: Core
        description: Core Module
        version: 1.0.0
        priority: 0
        providers:
          - Modules\Core\Providers\CoreServiceProvider
        dependencies: []
        YAML;

            $parser = new ModuleManifestParser();

            $manifest = $parser->parse($yaml);

            $this->assertIsArray($manifest);

            $this->assertSame('Core', $manifest['name']);
            $this->assertSame('Core Module', $manifest['description']);
            $this->assertSame('1.0.0', $manifest['version']);
            $this->assertSame(0, $manifest['priority']);

            $this->assertSame(
                [
                    'Modules\Core\Providers\CoreServiceProvider',
                ],
                $manifest['providers']
            );

            $this->assertSame(
                [],
                $manifest['dependencies']
            );
      }

      // public function test_throws_exception_for_invalid_yaml(): void
      //   {
      //       $yaml = <<<'YAML'
      //   name: Core
      //   description: Core Module
      //   version:
      //     - invalid:
      //   priority: 0
      //   YAML;

      //       $parser = new ModuleManifestParser();

      //       $this->expectException(\InvalidArgumentException::class);
      //       $this->expectExceptionMessage(
      //           'Invalid module manifest YAML.'
      //       );

      //       $parser->parse($yaml);
      //   }

      public function test_throws_exception_for_invalid_yaml(): void
      {
            $yaml = <<<'YAML'
        name: Core
        description: Core Module
        providers: [
        YAML;

            $parser = new ModuleManifestParser();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid module manifest YAML.');

            $parser->parse($yaml);
      }

  public function test_parses_complex_manifest(): void
    {
        $yaml = <<<'YAML'
    name: Core
    description: Core Module
    version: 1.0.0
    priority: 100

    providers:
      - Modules\Core\Providers\CoreServiceProvider
      - Modules\Core\Providers\RouteServiceProvider

    dependencies:
      - Shared
      - Auth
    YAML;

        $parser = new ModuleManifestParser();

        $manifest = $parser->parse($yaml);

        $this->assertSame('Core', $manifest['name']);
        $this->assertSame('Core Module', $manifest['description']);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertSame(100, $manifest['priority']);

        $this->assertSame(
            [
                'Modules\Core\Providers\CoreServiceProvider',
                'Modules\Core\Providers\RouteServiceProvider',
            ],
            $manifest['providers']
        );

        $this->assertSame(
            [
                'Shared',
                'Auth',
            ],
            $manifest['dependencies']
        );
    }
}