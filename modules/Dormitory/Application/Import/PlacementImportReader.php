<?php

namespace Modules\Dormitory\Application\Import;

use DateTimeImmutable;
use Modules\Core\Support\Import\ImportFileException;
use Modules\Core\Support\Import\SpreadsheetReader;

/**
 * Membaca file impor penempatan .xlsx / .csv (PRD-008 §20) lewat SpreadsheetReader Core: lembar "Penempatan"
 * (atau lembar pertama), maks. 1.000 baris.
 */
final class PlacementImportReader
{
    public const MAX_ROWS = 1000;

    private const SHEET_NAME = 'penempatan';

    public function __construct(
        private readonly SpreadsheetReader $reader,
    ) {}

    /**
     * @param  'xlsx'|'csv'  $extension
     * @return list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>
     *
     * @throws ImportFileException
     */
    public function read(string $path, string $extension): array
    {
        return $this->reader->read(
            $path,
            $extension,
            self::SHEET_NAME,
            PlacementImportColumns::keys(),
            PlacementImportColumns::requiredKeys(),
            self::MAX_ROWS,
            'penempatan',
        );
    }
}
