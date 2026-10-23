<?php

namespace Modules\Core\Domain\Person\Exceptions;

use DomainException;

/**
 * NIK yang diisi sudah tercatat atas nama orang lain (PRD-000 §5).
 *
 * Pemanggil (modul) yang menyusun pesan untuk pengguna: hanya boleh menyebut pemiliknya bila
 * orang itu anggota yayasan yang sama, agar data yayasan lain tidak bocor.
 */
final class NikTakenException extends DomainException
{
    public function __construct(
        public readonly string $ownerPersonId,
    ) {
        parent::__construct('NIK sudah terdaftar.');
    }
}
