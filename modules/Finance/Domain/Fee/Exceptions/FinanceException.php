<?php

namespace Modules\Finance\Domain\Fee\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan keuangan (PRD-003). Pesannya aman ditampilkan ke pengguna dan menyebut isian form
 * yang bermasalah ($field).
 */
final class FinanceException extends DomainException
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
