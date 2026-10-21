<?php

namespace Modules\Academic\Domain\Attendance\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan presensi (PRD-002 §17). Pesannya aman ditampilkan ke pengguna dan menyebut
 * isian form yang bermasalah ($field).
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
