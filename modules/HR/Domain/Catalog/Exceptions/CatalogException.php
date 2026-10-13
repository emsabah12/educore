<?php

namespace Modules\HR\Domain\Catalog\Exceptions;

use DomainException;
use Modules\HR\Domain\Catalog\CatalogKind;

/**
 * Pelanggaran aturan katalog SDM. Pesannya aman ditampilkan ke pengguna
 * (Bahasa Indonesia, tanpa detail teknis).
 */
final class CatalogException extends DomainException
{
    public static function invalidCode(): self
    {
        return new self('Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.');
    }

    public static function invalidName(): self
    {
        return new self('Nama wajib diisi, maksimal 100 karakter.');
    }

    public static function duplicateCode(CatalogKind $kind, string $code): self
    {
        return new self("Kode {$code} sudah dipakai di katalog ".mb_strtolower($kind->label()).'.');
    }

    /**
     * Pesan sengaja sama untuk "tidak ada" dan "milik yayasan lain".
     */
    public static function notFound(): self
    {
        return new self('Data katalog tidak ditemukan di yayasan ini.');
    }
}
