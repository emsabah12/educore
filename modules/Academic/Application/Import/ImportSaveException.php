<?php

namespace Modules\Academic\Application\Import;

use DomainException;

/**
 * Impor siswa tidak bisa disimpan; tidak ada satu pun siswa yang tersimpan (PRD-002 AC-1e, pola
 * OD-HR-05). Pesannya aman ditampilkan ke pengguna.
 */
final class ImportSaveException extends DomainException
{
    public static function hasErrors(int $count): self
    {
        return new self("Ada {$count} baris yang salah. Perbaiki file lalu unggah ulang; tidak ada data yang disimpan.");
    }

    public static function warningsNotConfirmed(): self
    {
        return new self('Centang konfirmasi bahwa baris bertanda peringatan adalah orang yang berbeda.');
    }

    public static function rowFailed(int $line, string $reason): self
    {
        return new self("Baris {$line}: {$reason} Tidak ada data yang disimpan.");
    }
}
