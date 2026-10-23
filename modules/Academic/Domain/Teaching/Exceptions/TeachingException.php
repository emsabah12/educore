<?php

namespace Modules\Academic\Domain\Teaching\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan presensi mengajar & jadwal piket (PRD-002 §20). Pesannya aman ditampilkan ke
 * pengguna dan menyebut isian form yang bermasalah ($field).
 */
final class TeachingException extends DomainException
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
