<?php

namespace Modules\Academic\Domain\Student\Exceptions;

use DomainException;

/**
 * Hasil pemeriksaan sebelum menyimpan siswa baru (PRD-002 §4.5, AC-1b): SEMUA masalah yang
 * ditemukan sekaligus, agar operator tidak perlu menebak isian mana yang salah.
 *
 * - $errors     : pesan per isian form, mis. ['nisn' => 'NISN ini sudah …'].
 * - $candidates : anggota yayasan dengan nama sama ("mungkin orang yang sama"); operator memilih
 *                 salah satu atau menyatakan orang berbeda, lalu menyimpan ulang.
 */
final class StudentCheckFailed extends DomainException
{
    /**
     * @param  array<string, string>  $errors
     * @param  list<array{membership_id: string, label: string}>  $candidates
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $candidates,
        public readonly string $name,
    ) {
        parent::__construct('Data siswa belum bisa disimpan.');
    }

    /**
     * Pesan kotak peringatan nama sama; null bila tidak ada calon.
     */
    public function duplicateMessage(): ?string
    {
        if ($this->candidates === []) {
            return null;
        }

        return "Nama \"{$this->name}\" sama dengan anggota yayasan di bawah ini. Bila orangnya sama, pilih orang "
            .'tersebut agar hanya pendaftarannya yang ditambahkan. Bila memang orang berbeda, pilih "Orang berbeda", lalu simpan ulang.';
    }
}
