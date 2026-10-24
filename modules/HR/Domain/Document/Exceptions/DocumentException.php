<?php

namespace Modules\HR\Domain\Document\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan dokumen pegawai (PRD-001 §15). Pesannya aman ditampilkan ke pengguna dan menyebut
 * isian form yang bermasalah ($field).
 */
final class DocumentException extends DomainException
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
