<?php

namespace Modules\HR\Application\Import;

use Modules\HR\Application\Employee\NewEmployeeData;

/**
 * Hasil pemeriksaan satu baris file impor (PRD-001 §6.2).
 *
 * $data hanya terisi bila baris tidak punya kesalahan; dipakai saat Simpan (HR-1e-b).
 */
final readonly class EmployeeImportRow
{
    public const READY = 'SIAP';

    public const WARNING = 'PERINGATAN';

    public const ERROR = 'SALAH';

    /**
     * @param  list<array{column: string, label: string, message: string}>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $line,
        public array $errors,
        public array $warnings,
        public ?string $name,
        public ?string $birthDate,
        public ?string $organization,
        public ?string $position,
        public ?string $employmentType,
        public ?NewEmployeeData $data,
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
     * Data untuk halaman pratinjau. Data pribadi selain tanggal lahir tidak dikirim ke browser;
     * tanggal lahir perlu agar admin bisa menilai peringatan orang ganda.
     *
     * @return array{line: int, status: string, name: string|null, birthDate: string|null, organization: string|null, position: string|null, employmentType: string|null, errors: list<array{column: string, label: string, message: string}>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'status' => $this->status(),
            'name' => $this->name,
            'birthDate' => $this->birthDate,
            'organization' => $this->organization,
            'position' => $this->position,
            'employmentType' => $this->employmentType,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
