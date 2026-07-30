<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Contracts;

interface UnitOfWorkInterface
{
    /**
     * Menjalankan seluruh operasi di dalam satu transaksi database.
     *
     * @template TReturn
     *
     * @param callable():TReturn $callback
     *
     * @return TReturn
     */
    public function execute(callable $callback): mixed;

    /**
     * Mengumpulkan repository event selama transaksi berjalan.
     */
    public function collect(
        RepositoryEventInterface $event,
    ): void;

    /**
     * Mengambil seluruh event lalu mengosongkan koleksi.
     *
     * @return array<RepositoryEventInterface>
     */
    public function pullEvents(): array;

    /**
     * Menghapus seluruh event yang masih tersimpan.
     */
    public function clearEvents(): void;
}
