<?php

namespace Modules\Academic\Domain\Student\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan data siswa (PRD-002 §4). Pesannya aman ditampilkan ke pengguna
 * (Bahasa Indonesia, tanpa detail teknis) dan menyebut field form yang bermasalah ($field).
 */
final class StudentException extends DomainException
{
    private function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function make(string $field, string $message): self
    {
        return new self($field, $message);
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

    /**
     * Pesan sama untuk "tidak ada" dan "milik yayasan lain", agar data yayasan lain tidak bocor.
     */
    public static function organizationNotFound(): self
    {
        return new self('organization_id', 'Lembaga tidak ditemukan di yayasan ini.');
    }

    public static function notALembaga(): self
    {
        return new self('organization_id', 'Siswa hanya bisa didaftarkan ke lembaga (bukan unit atau biro).');
    }

    public static function organizationInactive(): self
    {
        return new self('organization_id', 'Lembaga sudah nonaktif. Pilih lembaga yang aktif.');
    }

    public static function duplicateNisn(): self
    {
        return new self('nisn', 'NISN ini sudah dipakai siswa lain di yayasan ini.');
    }

    public static function nisnMismatch(): self
    {
        return new self('nisn', 'NISN berbeda dengan yang sudah tercatat untuk siswa ini. Kosongkan bila tidak ingin mengubahnya.');
    }

    public static function duplicateStudentNumber(string $number): self
    {
        return new self('student_number', "Nomor induk {$number} sudah dipakai siswa lain di lembaga ini.");
    }

    public static function alreadyEnrolled(): self
    {
        return new self('organization_id', 'Orang ini sudah terdaftar aktif di lembaga tersebut.');
    }

    public static function candidateInvalid(): self
    {
        return new self('existing_membership_id', 'Pilihan orang tidak cocok dengan nama yang diisi. Periksa lagi daftar orang dengan nama sama.');
    }

    public static function membershipInactive(): self
    {
        return new self('existing_membership_id', 'Keanggotaan orang ini di yayasan sudah nonaktif.');
    }

    public static function tooManyGuardians(int $max): self
    {
        return new self('guardians', "Paling banyak {$max} orang tua/wali.");
    }

    public static function guardianListedTwice(int $index): self
    {
        return new self("guardians.{$index}.name", 'Orang tua/wali ini tercatat dua kali.');
    }

    public static function guardianIsStudent(int $index): self
    {
        return new self("guardians.{$index}.name", 'Orang tua/wali tidak boleh siswa itu sendiri.');
    }

    public static function primaryGuardianInvalid(): self
    {
        return new self('primary_guardian', 'Pilih salah satu orang tua/wali sebagai kontak utama.');
    }

    public static function nikInvalid(string $field = 'nik'): self
    {
        return new self($field, 'NIK harus 16 angka.');
    }

    public static function nikMismatch(): self
    {
        return new self('nik', 'NIK berbeda dengan yang sudah tercatat untuk orang ini. Kosongkan bila tidak ingin mengubahnya.');
    }

    public static function nikCannotBeRemoved(): self
    {
        return new self('nik', 'NIK yang sudah tercatat tidak bisa dikosongkan. Perbaiki angkanya bila salah.');
    }

    public static function conflict(): self
    {
        return new self('form', 'Data berubah saat disimpan (mungkin diisi bersamaan). Muat ulang halaman lalu coba lagi.');
    }
}
