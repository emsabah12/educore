<?php

namespace Modules\HR\Domain\Performance\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan penilaian kinerja (PRD-001 §19.2). Pesannya aman ditampilkan ke pengguna dan menyebut isian
 * form yang bermasalah ($field).
 */
final class PerformanceException extends DomainException
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
