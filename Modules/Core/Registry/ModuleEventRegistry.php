<?php

declare(strict_types=1);

namespace Modules\Core\Registry;

final class ModuleEventRegistry
{
    /**
     * Menyimpan peta hubungan antara Event (key) dan himpunan Listeners (array of strings).
     *
     * @var array<string, list<string>>
     */
    private array $events = [];

    /**
     * Mendaftarkan sebuah listener ke dalam event tertentu.
     * Mencegah duplikasi listener yang sama untuk event yang sama.
     */
    public function register(string $eventClass, string $listenerClass): void
    {
        if (!isset($this->events[$eventClass])) {
            $this->events[$eventClass] = [];
        }

        if (!in_array($listenerClass, $this->events[$eventClass], true)) {
            $this->events[$eventClass][] = $listenerClass;
        }
    }

    /**
     * Mengambil semua listener yang terdaftar untuk suatu event tertentu.
     *
     * @return list<string>
     */
    public function getListenersFor(string $eventClass): array
    {
        return $this->events[$eventClass] ?? [];
    }

    /**
     * Mengambil seluruh map event dan listener yang berhasil dikumpulkan oleh Kernel.
     *
     * @return array<string, list<string>>
     */
    public function getAll(): array
    {
        return $this->events;
    }
}