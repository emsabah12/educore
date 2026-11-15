<?php

namespace Modules\Dormitory\Domain\Placement\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan penempatan (PRD-008 §5.3, §16.3). Pesannya aman ditampilkan ke pengguna dan menyebut isian
 * form yang bermasalah ($field).
 */
final class PlacementException extends DomainException
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
