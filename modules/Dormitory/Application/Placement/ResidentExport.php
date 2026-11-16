<?php

namespace Modules\Dormitory\Application\Placement;

use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan daftar penghuni (.xlsx, PRD-008 §10, §21.4): penghuni Aktif/Direncanakan sesuai saringan halaman
 * Penghuni, maks. PlacementDirectory::EXPORT_LIMIT baris. Hanya data yang juga tampil di halaman (§11 Privasi).
 *
 * @phpstan-import-type PlacementRow from PlacementDirectory
 */
final class ResidentExport
{
    public static function fileName(CarbonImmutable $today): string
    {
        return 'penghuni-asrama-'.$today->toDateString().'.xlsx';
    }

    /**
     * @param  list<PlacementRow>  $rows
     */
    public function write(string $path, array $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Penghuni');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            foreach ([5, 30, 10, 34, 26, 22, 12, 12, 12, 12, 13, 13, 14] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle(
                ['No', 'Nama', 'Kategori', 'Lembaga & kelas', 'Asrama', 'Gedung', 'Kamar', 'Bed', 'Loker', 'Status', 'Tanggal masuk', 'Rencana masuk', 'Perlu ditinjau'],
                (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7'),
            ));

            foreach ($rows as $index => $row) {
                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $row['name'],
                    $row['category_label'],
                    $row['detail'],
                    $row['dormitory'],
                    $row['building'],
                    $row['room'],
                    $row['bed'] ?? '',
                    $row['locker'] ?? '',
                    $row['status_label'],
                    self::day($row['check_in_on']),
                    self::day($row['planned_on']),
                    $row['needs_review'] ? 'Ya' : '',
                ]));
            }
        } finally {
            $writer->close();
        }
    }

    private static function day(?string $value): string
    {
        return $value === null ? '' : CarbonImmutable::parse($value)->format('d/m/Y');
    }
}
