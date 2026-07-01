<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Support\Facades\File;

final class ModuleStateRepository
{
    private string $path;

    public function __construct()
    {
        $this->path = storage_path('framework/modules.json');

        if (! File::exists($this->path)) {
            File::put($this->path, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    /**
     * @return array<string,bool>
     */
    public function all(): array
    {
        $json = File::get($this->path);

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    public function isEnabled(string $moduleId): bool
    {
        return $this->all()[$moduleId] ?? true;
    }

    public function enable(string $moduleId): void
    {
        $state = $this->all();

        $state[$moduleId] = true;

        $this->save($state);
    }

    public function disable(string $moduleId): void
    {
        $state = $this->all();

        $state[$moduleId] = false;

        $this->save($state);
    }

    /**
     * @param array<string,bool> $state
     */
    private function save(array $state): void
    {
        File::put(
            $this->path,
            json_encode(
                $state,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
    }
}