<?php

namespace Modules\HR\Domain\Payroll\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan komponen gaji (PRD-001 §16). Pesannya aman ditampilkan ke pengguna dan menyebut
 * isian form yang bermasalah ($field).
 */
final class PayrollException extends DomainException
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
