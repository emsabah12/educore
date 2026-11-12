<?php

namespace Modules\HR\Domain\Training\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan riwayat pelatihan (PRD-001 §19.1). Pesannya aman ditampilkan ke pengguna dan menyebut isian form
 * yang bermasalah ($field).
 */
final class TrainingException extends DomainException
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
