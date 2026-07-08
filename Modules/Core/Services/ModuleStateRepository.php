<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use JsonException;

final readonly class ModuleStateRepository
{
    /**
     * Menerapkan Dependency Injection untuk fleksibilitas isolasi lingkungan pengujian.
     */
    public function __construct(
        private string $filePath
    ) {
        $this->ensureFileExists();
    }

    /**
     * Mengambil semua data runtime state modul dari berkas JSON secara aman.
     *
     * @return array<string, array{enabled: bool}>
     */
    public function all(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false || trim($content) === '') {
            return [];
        }

        try {
            /** @var array<string, array{enabled: bool}> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (JsonException) {
            // Bersikap defensif jika berkas JSON rusak / tidak valid, kembalikan array kosong
            return [];
        }
    }

    /**
     * Memeriksa apakah status suatu modul sedang aktif (enabled).
     */
    public function isEnabled(string $name): bool
    {
        $states = $this->all();

        // Mengikuti spesifikasi: default state adalah false jika modul belum tercatat
        return $states[$name]['enabled'] ?? false;
    }

    /**
     * Mengaktifkan status modul dan menulisnya ke dalam berkas JSON.
     */
    public function enable(string $name): void
    {
        $states = $this->all();
        $states[$name] = ['enabled' => true];

        $this->save($states);
    }

    /**
     * Menonaktifkan status modul dan menulisnya ke dalam berkas JSON.
     */
    public function disable(string $name): void
    {
        $states = $this->all();
        $states[$name] = ['enabled' => false];

        $this->save($states);
    }

    /**
     * Menulis ulang data state ke dalam berkas dengan proteksi Concurrency Berkas (LOCK_EX).
     *
     * @param array<string, array{enabled: bool}> $states
     */
    private function save(array $states): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $jsonContent = json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        
        // LOCK_EX mencegah korupsi data akibat tabrakan penulisan berkas simultan
        file_put_contents($this->filePath, $jsonContent, LOCK_EX);
    }

    /**
     * Menjamin berkas JSON siap pakai sejak komponen pertama kali di-resolve.
     */
    private function ensureFileExists(): void
    {
        if (!file_exists($this->filePath)) {
            $this->save([]);
        }
    }
}