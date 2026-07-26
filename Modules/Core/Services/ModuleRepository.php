<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Platform\Module\Domain\ModuleDefinition;
use Modules\Core\Platform\Registry\ModuleRegistry;

final readonly class ModuleRepository
{
    /**
     * Konstruktor mengikat runtime storage murni (ModuleRegistry)
     */
    public function __construct(
        private ModuleRegistry $registry
    ) {}

    /**
     * Mengambil seluruh koleksi objek ModuleDefinition yang terdaftar
     * 
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    /**
     * Mencari satu definisi spesifik berdasarkan nama modul
     */
    public function find(string $name): ?ModuleDefinition
    {
        return $this->registry->has($name) ? $this->registry->get($name) : null;
    }

    /**
     * Memeriksa keberadaan modul di dalam registry metadata
     */
    public function has(string $name): bool
    {
        return $this->registry->has($name);
    }

    /**
     * Menghitung total modul yang berhasil dimuat oleh sistem
     */
    public function count(): int
    {
        return $this->registry->count();
    }
}
