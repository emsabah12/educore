<?php

namespace Modules\Core\Support\Import;

use DomainException;

/**
 * File impor tidak bisa dipakai sama sekali (rusak, format salah, judul kolom kurang, terlalu banyak
 * baris). Pesannya aman ditampilkan ke pengguna. $noun = isi file, mis. "pegawai" atau "siswa".
 */
final class ImportFileException extends DomainException
{
    public static function unreadable(): self
    {
        return new self('File tidak bisa dibaca. Pastikan file berformat .xlsx atau .csv dan tidak rusak.');
    }

    public static function empty(): self
    {
        return new self('File kosong. Baris pertama harus berisi judul kolom seperti di template.');
    }

    /**
     * @param  list<string>  $labels
     */
    public static function missingHeaders(array $labels): self
    {
        return new self('Judul kolom wajib tidak ditemukan: '.implode(', ', $labels).'. Pakai template impor.');
    }

    public static function duplicateHeader(string $header): self
    {
        return new self("Judul kolom \"{$header}\" muncul lebih dari sekali.");
    }

    public static function noRows(string $noun): self
    {
        return new self("Tidak ada baris data {$noun} di bawah judul kolom.");
    }

    public static function tooManyRows(int $max, string $noun): self
    {
        return new self("File berisi lebih dari {$max} baris {$noun}. Bagi menjadi beberapa file.");
    }
}
