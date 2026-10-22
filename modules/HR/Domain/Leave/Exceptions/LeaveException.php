<?php

namespace Modules\HR\Domain\Leave\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan cuti (PRD-001 §13). Pesannya aman ditampilkan ke pengguna dan menyebut isian
 * form yang bermasalah ($field).
 */
final class LeaveException extends DomainException
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
