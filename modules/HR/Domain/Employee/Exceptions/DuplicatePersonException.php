<?php

namespace Modules\HR\Domain\Employee\Exceptions;

use DomainException;

/**
 * Peringatan "mungkin orang yang sama" (PRD-001 §6, [LAMA] RISK-HR-004): sudah ada anggota
 * yayasan dengan nama yang sama (dan mungkin juga tanggal lahir yang sama). Admin boleh
 * melanjutkan setelah konfirmasi.
 */
final class DuplicatePersonException extends DomainException
{
    /**
     * @param  list<string>  $matches  keterangan singkat orang yang mirip, mis. "Siti Aminah (pegawai, lahir 1990-05-17)"
     */
    public function __construct(
        public readonly array $matches,
        public readonly bool $sameBirthDate = false,
    ) {
        parent::__construct(
            ($sameBirthDate
                ? 'Sudah ada anggota yayasan dengan nama dan tanggal lahir yang sama: '
                : 'Sudah ada anggota yayasan dengan nama yang sama: ')
            .implode(', ', $matches).'. '
            .'Bila orangnya sama, pilih "Anggota yayasan". Bila memang orang berbeda, centang konfirmasi lalu simpan ulang.'
        );
    }
}
