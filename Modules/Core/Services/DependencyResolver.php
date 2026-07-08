<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Manifest\ModuleDefinition;
use Modules\Core\Exceptions\CircularDependencyException;
use Modules\Core\Exceptions\MissingModuleDependencyException;

final readonly class DependencyResolver
{
    /**
     * Menyelesaikan urutan dependensi menggunakan algoritma Topological Sort (Kahn's / DFS Post-Order).
     *
     * @param array<string, ModuleDefinition> $modules Array berisi seluruh ModuleDefinition terdaftar, berindeks nama modul.
     * @return array<int, ModuleDefinition> List urutan ModuleDefinition yang siap di-boot dengan aman.
     *
     * @throws MissingModuleDependencyException
     * @throws CircularDependencyException
     */
    public function resolve(array $modules): array
    {
        $resolved = [];
        $visiting = [];

        // REFACTOR DEFENSYIF: Ubah array menjadi asosiatif berbasis nama modul asli
        $normalizedModules = [];
        foreach ($modules as $definition) {
            $normalizedModules[$definition->name] = $definition;
        }

        foreach ($normalizedModules as $moduleName => $definition) {
            $this->visit($moduleName, $normalizedModules, $resolved, $visiting);
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, ModuleDefinition> $modules
     * @param array<string, ModuleDefinition> $resolved
     * @param array<string, bool> $visiting
     */
    private function visit(
        string $moduleName,
        array $modules,
        array &$resolved,
        array &$visiting
    ): void {
        // Jika sudah selesai diproses, lewati
        if (isset($resolved[$moduleName])) {
            return;
        }

        // Jika modul sedang dalam proses pengecekan di cabang yang sama, artinya ada Circular Dependency!
        if (isset($visiting[$moduleName])) {
            $cyclePath = implode(' -> ', array_keys($visiting)) . ' -> ' . $moduleName;
            throw CircularDependencyException::forModule($moduleName, $cyclePath);
        }

        // Tandai modul sedang dikunjungi (stack)
        $visiting[$moduleName] = true;

        // Ambil definisi modul untuk melihat dependensinya
        $definition = $modules[$moduleName] ?? null;

        if ($definition === null) {
            // Kasus ini ditangani jika ada modul luar memanggil dependensi yang tidak terdaftar di sistem
            return;
        }

        foreach ($definition->dependencies as $dependency) {
            // Fail-Fast: Cek jika modul prasyarat tidak ada di daftar modul yang aktif/ditemukan
            if (!isset($modules[$dependency])) {
                throw MissingModuleDependencyException::forModule($moduleName, $dependency);
            }

            $this->visit($dependency, $modules, $resolved, $visiting);
        }

        // Hapus dari stack visiting dan masukkan ke hasil akhir yang sudah aman (resolved)
        unset($visiting[$moduleName]);
        $resolved[$moduleName] = $definition;
    }
}