<?php

namespace Modules\Core\Domain\Settings\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan scoped settings. Pesannya aman ditampilkan ke pengguna.
 */
final class SettingException extends DomainException
{
    public static function invalidKey(string $key): self
    {
        return new self("Format kode aturan tidak valid: {$key}.");
    }

    public static function unknownKey(string $key): self
    {
        return new self("Aturan {$key} belum didefinisikan oleh modul mana pun.");
    }

    public static function organizationNotFound(): self
    {
        return new self('Lembaga/unit tidak ditemukan di yayasan ini.');
    }

    public static function invalidValue(string $message): self
    {
        return new self("Nilai aturan tidak valid: {$message}");
    }

    public static function lockedFromAbove(): self
    {
        return new self('Aturan ini sudah dikunci oleh tingkat di atasnya, sehingga tidak bisa diubah di sini.');
    }
}
