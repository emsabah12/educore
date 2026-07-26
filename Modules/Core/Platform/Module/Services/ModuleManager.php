<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Module\Services;

use Modules\Core\Exceptions\ModuleNotFoundException;
use Modules\Core\Services\ModuleRepository;
use Modules\Core\Services\ModuleStateRepository;

final readonly class ModuleManager
{
    /**
     * Menggunakan Lightweight Command Query Separation (CQS).
     * Seluruh operasi baca didelegasikan ke ModuleRepository,
     * sedangkan operasi mutasi status dicatat ke ModuleStateRepository.
     */
    public function __construct(
        private ModuleRepository $repository,
        private ModuleStateRepository $stateRepository
    ) {}

    public function isEnabled(string $name): bool
    {
        $this->ensureModuleExists($name);

        return $this->stateRepository->isEnabled($name);
    }

    public function enable(string $name): void
    {
        $this->ensureModuleExists($name);

        $this->stateRepository->enable($name);
    }

    public function disable(string $name): void
    {
        $this->ensureModuleExists($name);

        $this->stateRepository->disable($name);
    }

    /**
     * Sesuai business rule platform kernel: Fail Fast jika modul tidak terdaftar.
     * Ensure properti $this->repository diakses dengan aman tanpa memicu undefined property.
     */
    private function ensureModuleExists(string $name): void
    {
        if (!$this->repository->has($name)) {
            throw new ModuleNotFoundException("Module [{$name}] is not registered in the system.");
        }
    }

    /**
     * Mengambil seluruh definisi objek modul yang saat ini aktif di sistem secara aman.
     * * REFACTOR: Mematuhi CQS dengan memanggil $this->repository->all() secara sah.
     *
     * @return array<int, \Modules\Core\Entities\ModuleDefinition>
     */
    public function getEnabledModules(): array
    {
        // Mengambil kumpulan entitas ModuleDefinition melalui Service Repository internal Anda
        $allModules = $this->repository->all();
        $enabledModules = [];

        foreach ($allModules as $definition) {
            if ($this->isEnabled($definition->name)) {
                $enabledModules[] = $definition;
            }
        }

        return $enabledModules;
    }
}
