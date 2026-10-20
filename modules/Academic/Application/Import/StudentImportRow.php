<?php

namespace Modules\Academic\Application\Import;

use Modules\Academic\Application\Student\NewStudentData;

/**
 * Hasil pemeriksaan satu baris file impor siswa (PRD-002 AC-1e).
 *
 * $data hanya terisi bila baris tidak punya kesalahan; isinya sama persis dengan form Tambah siswa
 * dan dipakai saat Simpan (AC-1e-b).
 */
final readonly class StudentImportRow
{
    public const READY = 'SIAP';

    public const WARNING = 'PERINGATAN';

    public const ERROR = 'SALAH';

    /** Orang baru, atau siswa yang sudah tercatat (NISN & nama cocok) yang didaftarkan ke lembaga ini. */
    public const MODE_NEW = 'BARU';

    public const MODE_EXISTING = 'LAMA';

    /**
     * @param  list<array{column: string, label: string, message: string}>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $line,
        public array $errors,
        public array $warnings,
        public string $mode,
        public ?string $name,
        public ?string $birthDate,
        public ?string $nisn,
        public ?string $studentNumber,
        public ?string $className,
        public int $guardians,
        public ?NewStudentData $data,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->errors !== [] => self::ERROR,
            $this->warnings !== [] => self::WARNING,
            default => self::READY,
        };
    }

    /**
     * Data untuk halaman pratinjau. Data pribadi selain tanggal lahir (perlu untuk menilai orang
     * ganda) tidak dikirim ke browser; orang tua/wali hanya jumlahnya.
     *
     * @return array{line: int, status: string, mode: string, name: string|null, birthDate: string|null, nisn: string|null, studentNumber: string|null, className: string|null, guardians: int, errors: list<array{column: string, label: string, message: string}>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'status' => $this->status(),
            'mode' => $this->mode,
            'name' => $this->name,
            'birthDate' => $this->birthDate,
            'nisn' => $this->nisn,
            'studentNumber' => $this->studentNumber,
            'className' => $this->className,
            'guardians' => $this->guardians,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
