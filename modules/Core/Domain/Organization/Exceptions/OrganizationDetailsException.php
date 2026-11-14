<?php

namespace Modules\Core\Domain\Organization\Exceptions;

use DomainException;

/**
 * Isian data resmi lembaga tidak sah (PRD-000 §4.6). Pesannya aman ditampilkan & menyebut isiannya ($field).
 */
final class OrganizationDetailsException extends DomainException
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
