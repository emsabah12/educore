<?php

namespace Modules\Core\Domain\Tenancy;

use RuntimeException;

/**
 * Penyimpan konteks kerja untuk SATU request (didaftarkan sebagai binding `scoped`).
 *
 * Kosong di perintah konsol, seeder, dan halaman yang tidak memakai middleware
 * EnsureWorkContext (mis. login, profil, pilih yayasan).
 */
final class TenantContext
{
    private ?WorkContext $context = null;

    public function set(WorkContext $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function get(): ?WorkContext
    {
        return $this->context;
    }

    public function has(): bool
    {
        return $this->context !== null;
    }

    /**
     * Konteks wajib ada. Dipakai kode yang memang hanya boleh berjalan
     * di dalam yayasan aktif; gagal keras bila dipanggil di luar itu.
     */
    public function require(): WorkContext
    {
        return $this->context ?? throw new RuntimeException('Konteks kerja belum terbentuk untuk request ini.');
    }
}
