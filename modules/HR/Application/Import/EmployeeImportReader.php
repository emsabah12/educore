<?php

namespace Modules\HR\Application\Import;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Sheet as CsvSheet;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;
use Throwable;

/**
 * Membaca file impor pegawai .xlsx / .csv menjadi baris mentah (PRD-001 §6.2).
 *
 * - Baris pertama = judul kolom. Kolom yang tidak dikenal diabaikan.
 * - XLSX: lembar bernama "Pegawai" bila ada, selain itu lembar pertama. Nilai sel dikembalikan apa
 *   adanya (teks, angka, tanggal) supaya pemeriksa bisa menolak NUPTK/HP yang tersimpan sebagai angka.
 * - CSV: pemisah "," atau ";" dikenali otomatis; UTF-8 (dengan/tanpa BOM) atau Windows-1252.
 * - Baris yang seluruhnya kosong dilewati; nomor baris tetap mengikuti nomor baris di file.
 */
final class EmployeeImportReader
{
    public const MAX_ROWS = 1000;

    private const SHEET_NAME = 'pegawai';

    /**
     * @param  'xlsx'|'csv'  $extension
     * @return list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>
     *
     * @throws ImportFileException
     */
    public function read(string $path, string $extension): array
    {
        try {
            $reader = $extension === 'xlsx'
                ? new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true))
                : $this->csvReader($path);

            $reader->open($path);

            try {
                return $this->rows($this->sheet($reader));
            } finally {
                $reader->close();
            }
        } catch (ImportFileException $exception) {
            throw $exception;
        } catch (Throwable) {
            // File dari pengguna tidak dipercaya: zip rusak, XML aneh, encoding salah → satu pesan umum.
            throw ImportFileException::unreadable();
        }
    }

    /**
     * @return list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>
     */
    private function rows(XlsxSheet|CsvSheet $sheet): array
    {
        /** @var array<int, string>|null $columns indeks sel => key kolom */
        $columns = null;
        $rows = [];
        $line = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $line++;
            $values = $this->values($row);

            if ($columns === null) {
                if ($this->isBlank($values)) {
                    continue;
                }

                $columns = $this->columns($values);

                continue;
            }

            $mapped = [];

            foreach ($columns as $index => $key) {
                $mapped[$key] = $values[$index] ?? null;
            }

            if ($this->isBlank($mapped)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ImportFileException::tooManyRows(self::MAX_ROWS);
            }

            $rows[] = ['line' => $line, 'values' => $mapped];
        }

        if ($columns === null) {
            throw ImportFileException::empty();
        }

        if ($rows === []) {
            throw ImportFileException::noRows();
        }

        return $rows;
    }

    /**
     * Petakan judul kolom ke key yang dikenal; pastikan kolom wajib ada.
     *
     * @param  array<int, bool|DateTimeImmutable|float|int|string|null>  $headers
     * @return array<int, string>
     */
    private function columns(array $headers): array
    {
        $known = array_flip(EmployeeImportColumns::keys());
        $columns = [];

        foreach ($headers as $index => $header) {
            if (! is_string($header)) {
                continue;
            }

            $key = EmployeeImportColumns::normalizeHeader($header);

            if (! isset($known[$key])) {
                continue;
            }

            if (in_array($key, $columns, true)) {
                throw ImportFileException::duplicateHeader(trim($header));
            }

            $columns[$index] = $key;
        }

        $missing = [];

        foreach (EmployeeImportColumns::requiredKeys() as $key) {
            if (! in_array($key, $columns, true)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw ImportFileException::missingHeaders($missing);
        }

        return $columns;
    }

    /**
     * @return array<int, bool|DateTimeImmutable|float|int|string|null>
     */
    private function values(Row $row): array
    {
        $values = [];

        foreach ($row->cells as $index => $cell) {
            $values[$index] = $this->cellValue($cell);
        }

        return $values;
    }

    private function cellValue(Cell $cell): bool|DateTimeImmutable|float|int|string|null
    {
        // Rumus Excel: pakai hasil terakhirnya. Teks CSV yang diawali "=" tetap dibaca sebagai teks.
        $value = $cell instanceof Cell\FormulaCell ? ($cell->getComputedValue() ?? $cell->getValue()) : $cell->getValue();

        if ($cell instanceof Cell\ErrorCell) {
            return '#ERROR';
        }

        // Teks berformat campuran (sebagian tebal, dsb.) digabung menjadi teks biasa.
        if ($cell instanceof Cell\TextRunCell) {
            return implode('', array_map(fn (TextRun $run): string => $run->text, $cell->getValue()));
        }

        if ($value instanceof DateTimeImmutable || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        // Kosong atau durasi: tidak dipakai di impor.
        return null;
    }

    /**
     * @param  array<array-key, bool|DateTimeImmutable|float|int|string|null>  $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && ! (is_string($value) && trim($value) === '')) {
                return false;
            }
        }

        return true;
    }

    private function sheet(XlsxReader|CsvReader $reader): XlsxSheet|CsvSheet
    {
        $first = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $first ??= $sheet;

            if (mb_strtolower(trim($sheet->getName())) === self::SHEET_NAME) {
                return $sheet;
            }
        }

        if ($first === null) {
            throw ImportFileException::empty();
        }

        return $first;
    }

    /**
     * Pemisah & encoding CSV ditebak dari awal file: Excel berbahasa Indonesia menyimpan dengan ";".
     */
    private function csvReader(string $path): CsvReader
    {
        $head = @file_get_contents($path, length: 65536);

        if ($head === false) {
            throw ImportFileException::unreadable();
        }

        $head = (string) preg_replace('/^\xEF\xBB\xBF/', '', $head);
        $firstLine = strtok($head, "\r\n");
        $firstLine = $firstLine === false ? '' : $firstLine;

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        // Potong di akhir baris terakhir yang utuh agar karakter multibyte tidak terbelah.
        $lastNewline = strrpos($head, "\n");
        $sample = $lastNewline === false ? $head : substr($head, 0, $lastNewline);
        $encoding = mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: $delimiter,
            ENCODING: $encoding,
        ));
    }
}
