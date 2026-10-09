<?php

namespace Modules\Core\Domain\Organization\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan pohon lembaga. Pesannya aman ditampilkan ke pengguna
 * (Bahasa Indonesia, tanpa detail teknis) sesuai PRD-000 §9.
 */
final class OrganizationTreeException extends DomainException
{
    public static function tenantNotFound(): self
    {
        return new self('Yayasan tidak ditemukan.');
    }

    public static function organizationNotFound(): self
    {
        return new self('Lembaga/unit tidak ditemukan di yayasan ini.');
    }

    /**
     * Pesan sengaja sama untuk "tidak ada" dan "milik yayasan lain",
     * agar keberadaan data yayasan lain tidak bocor.
     */
    public static function parentNotFound(): self
    {
        return new self('Induk tidak ditemukan di yayasan ini.');
    }

    public static function parentInactive(): self
    {
        return new self('Induk sudah nonaktif. Aktifkan induknya atau pilih induk lain.');
    }

    public static function maxDepthExceeded(int $maxLevel): self
    {
        return new self("Pohon lembaga maksimal {$maxLevel} tingkat.");
    }

    public static function cycle(): self
    {
        return new self('Lembaga/unit tidak bisa dipindah ke bawah dirinya sendiri atau turunannya.');
    }

    public static function duplicateCode(string $code): self
    {
        return new self("Kode {$code} sudah dipakai di yayasan ini.");
    }

    public static function invalidCode(): self
    {
        return new self('Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–50 karakter.');
    }

    public static function invalidName(): self
    {
        return new self('Nama wajib diisi, maksimal 200 karakter.');
    }

    public static function lembagaRequiresClassification(): self
    {
        return new self('Lembaga wajib memiliki kategori dan jenjang.');
    }

    public static function classificationOnlyForLembaga(): self
    {
        return new self('Kategori dan jenjang hanya untuk node berjenis lembaga.');
    }

    public static function hasActiveChildren(): self
    {
        return new self('Masih ada lembaga/unit aktif di bawahnya. Nonaktifkan dari tingkat paling bawah terlebih dahulu.');
    }
}
