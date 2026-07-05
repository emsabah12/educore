<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Manifest;

use Modules\Core\Exceptions\InvalidModuleManifestException;
use Modules\Core\Manifest\ModuleManifestValidator;
use Modules\Core\Tests\Builders\ManifestBuilder;
use PHPUnit\Framework\TestCase;

final class ModuleManifestValidatorTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $validator = new ModuleManifestValidator();

        $this->assertInstanceOf(
            ModuleManifestValidator::class,
            $validator
        );
    }

    public function test_accepts_valid_manifest(): void
    {
        $validator = new ModuleManifestValidator();

        $manifest = ManifestBuilder::make()->build();

        $validated = $validator->validate($manifest);

        $this->assertSame($manifest, $validated);
    }

    public function test_rejects_manifest_without_name(): void
        {
            $validator = new ModuleManifestValidator();

            $manifest = ManifestBuilder::make()->build();

            unset($manifest['name']);

            $this->expectException(InvalidModuleManifestException::class);

            $validator->validate($manifest);
        }


        public function test_rejects_invalid_field_type(): void
            {
                $validator = new ModuleManifestValidator();

                $manifest = ManifestBuilder::make()->build();

                $manifest['providers'] = 'invalid_type';

                $this->expectException(InvalidModuleManifestException::class);
                $this->expectExceptionMessage(
                    "Field 'providers' must be an array."
                );

                $validator->validate($manifest);
            }
}