<?php

namespace Modules\Dormitory\Domain\YearEnd;

use DomainException;

/** Proses akhir tahun ditolak (PRD-008 §19.2). Pesannya aman ditampilkan ke pengguna. */
final class YearEndException extends DomainException
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
