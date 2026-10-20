<?php

namespace Modules\Academic\Application\Import;

use DateTimeImmutable;
use Modules\Core\Support\Import\SpreadsheetReader;

/**
 * Membaca file impor siswa .xlsx / .csv (PRD-002 AC-1e) lewat SpreadsheetReader Core: lembar
 * "Siswa" (atau lembar pertama), maks. 1.000 baris.
 */
final class StudentImportReader
{
    public const MAX_ROWS = 1000;

    private const SHEET_NAME = 'siswa';

    public function __construct(
        private readonly SpreadsheetReader $reader,
    ) {}

    /**
     * @param  'xlsx'|'csv'  $extension
     * @return list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>
     */
    public function read(string $path, string $extension): array
    {
        return $this->reader->read(
            $path,
            $extension,
            self::SHEET_NAME,
            StudentImportColumns::keys(),
            StudentImportColumns::requiredKeys(),
            self::MAX_ROWS,
            'siswa',
        );
    }
}
