<?php

namespace Modules\Core\Domain\Identity\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan kelola akun login (PRD-000 §4.7 F5a). Pesannya aman ditampilkan ke
 * pengguna (Bahasa Indonesia, tanpa detail teknis) dan menyebut field form terkait ($field).
 */
final class AccountException extends DomainException
{
    private function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function membershipInactive(): self
    {
        return new self('form', 'Keanggotaan orang ini di yayasan sudah tidak aktif.');
    }

    public static function alreadyHasAccount(): self
    {
        return new self('form', 'Pegawai ini sudah punya akun login.');
    }

    public static function noAccount(): self
    {
        return new self('form', 'Pegawai ini belum punya akun login.');
    }

    public static function usernameInvalid(): self
    {
        return new self('username', 'Username wajib diisi: huruf kecil, angka, titik, garis bawah, atau tanda "-", panjang 3–50 karakter.');
    }

    public static function usernameTaken(): self
    {
        return new self('username', 'Username ini sudah dipakai akun lain.');
    }

    public static function emailInvalid(): self
    {
        return new self('email', 'Format email tidak valid (maksimal 255 karakter).');
    }

    public static function emailTaken(): self
    {
        return new self('email', 'Email ini sudah dipakai akun lain.');
    }

    public static function superadmin(): self
    {
        return new self('form', 'Ini akun Superadmin platform; aksesnya tidak bisa diatur dari halaman yayasan.');
    }

    public static function sharedWithOtherTenant(): self
    {
        return new self('form', 'Akun ini juga terdaftar di yayasan lain, sehingga tidak bisa diatur dari sini. Hubungi Superadmin.');
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self('form', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
    }

    public static function alreadyInactive(): self
    {
        return new self('form', 'Akun ini sudah nonaktif.');
    }

    public static function alreadyActive(): self
    {
        return new self('form', 'Akun ini sudah aktif.');
    }
}
