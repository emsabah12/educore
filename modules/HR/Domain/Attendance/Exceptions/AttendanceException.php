<?php

namespace Modules\HR\Domain\Attendance\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan presensi pegawai (PRD-001 §14). Pesannya aman ditampilkan ke pengguna dan
 * menyebut isian/aksi yang bermasalah ($field).
 */
final class AttendanceException extends DomainException
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
}
