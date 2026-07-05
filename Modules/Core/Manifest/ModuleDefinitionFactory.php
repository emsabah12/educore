<?php

declare(strict_types=1);

namespace Modules\Core\Manifest;

use Modules\Core\Entities\ModuleDefinition;

final readonly class ModuleDefinitionFactory
{
    public function __construct(
        private ModuleManifestValidator $validator,
    ) {
    }

    /**
     * Create ModuleDefinition from validated manifest.
     *
     * @param array<string, mixed> $manifest
     */
    public function make(array $manifest): ModuleDefinition
    {
        $manifest = $this->validator->validate($manifest);

        return ModuleDefinition::fromArray($manifest);
    }
}