<?php

namespace Modules\HR\Domain\Discipline\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan catatan disiplin (PRD-001 §18.1). Pesannya aman ditampilkan ke pengguna dan menyebut isian form
 * yang bermasalah ($field).
 */
final class DisciplineException extends DomainException
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
