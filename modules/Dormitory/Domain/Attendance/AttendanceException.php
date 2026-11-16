<?php

namespace Modules\Dormitory\Domain\Attendance;

use DomainException;

/** Absen asrama atau jenis absen ditolak (PRD-008 §22). Pesannya aman ditampilkan ke pengguna. */
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
