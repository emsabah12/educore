<?php

namespace Modules\HR\Domain\Employee\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan data pegawai. Pesannya aman ditampilkan ke pengguna (Bahasa Indonesia,
 * tanpa detail teknis) dan menyebut field form yang bermasalah ($field).
 */
final class EmployeeException extends DomainException
{
    private function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self('name', 'Nama lengkap wajib diisi, maksimal 200 karakter.');
    }

    public static function genderRequired(): self
    {
        return new self('gender', 'Jenis kelamin wajib dipilih.');
    }

    public static function futureDate(string $field, string $label): self
    {
        return new self($field, "{$label} tidak boleh di masa depan.");
    }

    public static function invalidFormat(string $field, string $message): self
    {
        return new self($field, $message);
    }

    /**
     * Pesan sama untuk "tidak ada" dan "milik yayasan lain", agar data yayasan lain tidak bocor.
     */
    public static function notFound(string $field, string $label): self
    {
        return new self($field, "{$label} tidak ditemukan di yayasan ini.");
    }

    public static function inactive(string $field, string $label): self
    {
        return new self($field, "{$label} sudah nonaktif. Pilih yang lain atau aktifkan kembali di Katalog SDM.");
    }

    public static function organizationInactive(): self
    {
        return new self('organization_id', 'Lembaga sudah nonaktif. Pilih lembaga yang aktif.');
    }

    public static function membershipInactive(): self
    {
        return new self('membership_id', 'Keanggotaan orang ini di yayasan sudah nonaktif.');
    }

    public static function alreadyEmployee(): self
    {
        return new self('membership_id', 'Orang ini sudah terdaftar sebagai pegawai.');
    }

    public static function duplicateEmployeeNumber(string $number): self
    {
        return new self('employee_number', "Nomor induk {$number} sudah dipakai pegawai lain.");
    }

    public static function duplicateNuptk(): self
    {
        return new self('nuptk', 'NUPTK ini sudah dipakai pegawai lain.');
    }

    /**
     * Pelanggaran aturan lain (urutan tanggal, status pegawai, dsb.) dengan pesan khusus.
     */
    public static function rule(string $field, string $message): self
    {
        return new self($field, $message);
    }

    public static function conflict(): self
    {
        return new self('form', 'Data berubah saat disimpan (mungkin oleh pengguna lain). Periksa lalu simpan ulang.');
    }
}
