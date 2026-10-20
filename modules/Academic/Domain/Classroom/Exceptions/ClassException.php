<?php

namespace Modules\Academic\Domain\Classroom\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan kelas (PRD-002 §4.2). Pesannya aman ditampilkan ke pengguna dan menyebut
 * isian form yang bermasalah ($field).
 */
final class ClassException extends DomainException
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

    public static function pastYear(): self
    {
        return new self('form', 'Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat, tidak bisa diubah.');
    }

    public static function duplicateName(string $name): self
    {
        return new self('name', "Nama kelas {$name} sudah dipakai di lembaga ini pada tahun ajaran yang sama.");
    }

    public static function homeroomNotActive(): self
    {
        return new self('homeroom_employee_id', 'Wali kelas harus pegawai aktif yang ditempatkan di lembaga ini.');
    }
}
