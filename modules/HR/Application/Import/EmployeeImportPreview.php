<?php

namespace Modules\HR\Application\Import;

/**
 * Hasil pemeriksaan seluruh file impor (PRD-001 §6.2). Tidak ada yang disimpan di tahap ini.
 */
final readonly class EmployeeImportPreview
{
    /**
     * @param  list<EmployeeImportRow>  $rows
     */
    public function __construct(
        public array $rows,
    ) {}

    public function count(string $status): int
    {
        return count(array_filter($this->rows, fn (EmployeeImportRow $row): bool => $row->status() === $status));
    }

    /**
     * Simpan hanya boleh bila tidak ada baris Salah [OWNER, OD-HR-05].
     */
    public function canSave(): bool
    {
        return $this->count(EmployeeImportRow::ERROR) === 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(EmployeeImportRow::WARNING) > 0;
    }

    /**
     * @return array{total: int, ready: int, warning: int, error: int, canSave: bool, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'total' => count($this->rows),
            'ready' => $this->count(EmployeeImportRow::READY),
            'warning' => $this->count(EmployeeImportRow::WARNING),
            'error' => $this->count(EmployeeImportRow::ERROR),
            'canSave' => $this->canSave(),
            'rows' => array_map(fn (EmployeeImportRow $row): array => $row->toArray(), $this->rows),
        ];
    }
}
