<?php

namespace Modules\Academic\Domain\Subject\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan mata pelajaran (PRD-002 §16 AC-3a). Pesannya aman ditampilkan ke pengguna dan
 * menyebut isian form yang bermasalah ($field).
 */
final class SubjectException extends DomainException
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

    public static function duplicateName(string $name): self
    {
        return new self('name', "Mata pelajaran {$name} sudah ada di lembaga ini.");
    }

    public static function duplicateCode(string $code): self
    {
        return new self('code', "Kode {$code} sudah dipakai mata pelajaran lain di lembaga ini.");
    }
}
