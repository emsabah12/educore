<?php

namespace Modules\HR\Application\Import;

use DateTimeImmutable;
use Modules\Core\Support\Import\ImportFileException as FileException;
use Modules\Core\Support\Import\SpreadsheetReader;

/**
 * Membaca file impor pegawai .xlsx / .csv menjadi baris mentah (PRD-001 §6.2). Cara bacanya ada di
 * Core (SpreadsheetReader, dipakai juga impor siswa); di sini hanya kolom & lembar milik pegawai.
 *
 * - Baris pertama = judul kolom. Kolom yang tidak dikenal diabaikan.
 * - XLSX: lembar bernama "Pegawai" bila ada, selain itu lembar pertama.
 * - CSV: pemisah "," atau ";" dikenali otomatis; UTF-8 atau Windows-1252.
 */
final class EmployeeImportReader
{
    public const MAX_ROWS = 1000;

    private const SHEET_NAME = 'pegawai';

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
        try {
            return $this->reader->read(
                $path,
                $extension,
                self::SHEET_NAME,
                EmployeeImportColumns::keys(),
                EmployeeImportColumns::requiredKeys(),
                self::MAX_ROWS,
                'pegawai',
            );
        } catch (FileException $exception) {
            throw new ImportFileException($exception->getMessage());
        }
    }
}
