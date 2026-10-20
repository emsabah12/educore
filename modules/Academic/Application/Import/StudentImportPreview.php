<?php

namespace Modules\Academic\Application\Import;

/**
 * Hasil pemeriksaan seluruh file impor siswa (PRD-002 AC-1e). Tidak ada yang disimpan di tahap ini.
 */
final readonly class StudentImportPreview
{
    /**
     * @param  list<StudentImportRow>  $rows
     */
    public function __construct(
        public array $rows,
    ) {}

    public function count(string $status): int
    {
        return count(array_filter($this->rows, fn (StudentImportRow $row): bool => $row->status() === $status));
    }

    /**
     * Simpan hanya boleh bila tidak ada baris Salah (pola HR-1e, OD-AC-07).
     */
    public function canSave(): bool
    {
        return $this->count(StudentImportRow::ERROR) === 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(StudentImportRow::WARNING) > 0;
    }

    /**
     * @return array{total: int, ready: int, warning: int, error: int, existing: int, canSave: bool, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'total' => count($this->rows),
            'ready' => $this->count(StudentImportRow::READY),
            'warning' => $this->count(StudentImportRow::WARNING),
            'error' => $this->count(StudentImportRow::ERROR),
            'existing' => count(array_filter($this->rows, fn (StudentImportRow $row): bool => $row->mode === StudentImportRow::MODE_EXISTING)),
            'canSave' => $this->canSave(),
            'rows' => array_map(fn (StudentImportRow $row): array => $row->toArray(), $this->rows),
        ];
    }
}
