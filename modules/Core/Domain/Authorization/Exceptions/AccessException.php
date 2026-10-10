<?php

namespace Modules\Core\Domain\Authorization\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan katalog akses & pemasangan role. Pesannya aman ditampilkan ke pengguna.
 */
final class AccessException extends DomainException
{
    public static function invalidPermissionKey(string $key): self
    {
        return new self("Format kode hak akses tidak valid: {$key}. Gunakan pola modul.resource.aksi, huruf kecil.");
    }

    public static function invalidRoleKey(string $key): self
    {
        return new self("Format kode role tidak valid: {$key}. Gunakan huruf kecil, angka, dan tanda -.");
    }

    public static function unknownPermission(string $key): self
    {
        return new self("Hak akses {$key} belum terdaftar di katalog.");
    }

    public static function unknownRole(string $key): self
    {
        return new self("Role {$key} belum terdaftar di katalog.");
    }

    public static function roleNotFound(): self
    {
        return new self('Role tidak ditemukan. Jalankan sinkronisasi katalog akses terlebih dahulu.');
    }

    public static function membershipInactive(): self
    {
        return new self('Keanggotaan di yayasan ini sudah nonaktif.');
    }

    public static function assignmentInactive(): self
    {
        return new self('Penugasan ini sudah nonaktif.');
    }
}
