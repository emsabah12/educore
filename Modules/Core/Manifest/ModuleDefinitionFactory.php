<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use Modules\Core\Entities\ModuleDefinition;

final class ModuleDefinitionFactory
{
    public function __construct(
        private readonly ManifestValidator $validator,
    ) {
    }

    public function make(array $manifest): ModuleDefinition
    {
        $this->validator->validate($manifest);

        return new ModuleDefinition(
            schema: $manifest['schema'],
            id: $manifest['id'],
            name: $manifest['name'],
            version: $manifest['version'],
            description: $manifest['description'],
            providers: $manifest['providers'],
            dependencies: $manifest['dependencies'],
            metadata: $manifest['metadata'],
            extra: $manifest['extra'],
        );
    }
}