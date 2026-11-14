<?php

namespace Modules\HR\Domain\Ptk\Exceptions;

use DomainException;

/**
 * Isian Data PTK tidak sah (PRD-001 §21.1). Pesannya aman ditampilkan & menyebut isiannya ($field).
 */
final class PtkException extends DomainException
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
