<?php

namespace Modules\Dormitory\Application\Report;

use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan laporan hunian (.xlsx, PRD-008 §21): lembar Ringkasan (per asrama: hunian sekarang + pergerakan dalam
 * rentang), Per kamar, dan Riwayat (daftar kejadian dalam rentang). Isi mengikuti saringan halaman.
 *
 * @phpstan-import-type DormitoryReport from OccupancyReport
 * @phpstan-import-type Metrics from OccupancyReport
 * @phpstan-import-type MovementRow from OccupancyReport
 * @phpstan-import-type Movement from OccupancyReport
 * @phpstan-import-type EventRow from OccupancyReport
 */
final class OccupancyReportExport
{
    public static function fileName(CarbonImmutable $today, ?string $dormitoryCode): string
    {
        return 'laporan-hunian-'.($dormitoryCode === null ? 'semua-asrama' : strtolower($dormitoryCode)).'-'.$today->toDateString().'.xlsx';
    }

    /**
     * @param  array{dormitories: list<DormitoryReport>, totals: Metrics}  $current
     * @param  array{dormitories: list<MovementRow>, totals: Movement}  $movements
     * @param  list<EventRow>  $events
     */
    public function write(string $path, array $current, array $movements, array $events, CarbonImmutable $from, CarbonImmutable $to, string $scopeLabel): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->summary($writer, $current, $movements, $from, $to, $scopeLabel);
            $writer->addNewSheetAndMakeItCurrent();
            $this->rooms($writer, $current);
            $writer->addNewSheetAndMakeItCurrent();
            $this->events($writer, $events, $from, $to);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  array{dormitories: list<DormitoryReport>, totals: Metrics}  $current
     * @param  array{dormitories: list<MovementRow>, totals: Movement}  $movements
     */
    private function summary(Writer $writer, array $current, array $movements, CarbonImmutable $from, CarbonImmutable $to, string $scopeLabel): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setSheetView(new SheetView(freezeRow: 4));
        self::widths($sheet, [30, 24, 8, 10, 9, 9, 9, 11, 10, 9, 10, 11, 11, 9, 10, 12, 10, 9, 10, 10, 9, 11]);

        $writer->addRow(Row::fromValuesWithStyle(['Laporan hunian asrama — '.$scopeLabel], (new Style)->withFontBold(true)));
        $writer->addRow(Row::fromValues(['Hunian per '.self::day(CarbonImmutable::now()).'; pergerakan '.self::day($from).' s.d. '.self::day($to)]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(self::header([
            'Asrama', 'Pemilik', 'Kamar', 'Kapasitas', 'Terisi', 'Santri', 'Pengawas', 'Direncanakan', 'Tersedia', '% hunian',
            'Kelebihan muatan', 'Bed/loker tidak tersedia',
            'Hunian awal', 'Masuk', 'Keluar asrama', 'Lulus/keluar lembaga', 'Akhir tahun', 'Keluar lainnya', 'Mutasi masuk', 'Mutasi keluar', 'Mutasi darurat', 'Hunian akhir',
        ]));

        $moves = [];

        foreach ($movements['dormitories'] as $row) {
            $moves[$row['id']] = $row['movement'];
        }

        foreach ($current['dormitories'] as $dormitory) {
            $writer->addRow(Row::fromValues([
                $dormitory['name'].($dormitory['is_active'] ? '' : ' (nonaktif)'),
                $dormitory['organization'],
                ...self::metricValues($dormitory['metrics']),
                ...self::movementValues($moves[$dormitory['id']] ?? OccupancyReport::emptyMovement()),
            ]));
        }

        $writer->addRow(Row::fromValuesWithStyle([
            'Total',
            '',
            ...self::metricValues($current['totals']),
            ...self::movementValues($movements['totals']),
        ], (new Style)->withFontBold(true)));
    }

    /**
     * @param  array{dormitories: list<DormitoryReport>, totals: Metrics}  $current
     */
    private function rooms(Writer $writer, array $current): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Per kamar');
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        self::widths($sheet, [28, 22, 8, 12, 10, 14, 10, 10, 10, 9, 9, 9, 11, 9, 9, 11, 11]);

        $writer->addRow(self::header([
            'Asrama', 'Gedung', 'Putra/Putri', 'Kamar', 'Status', 'Dasar kapasitas', 'Bed tersedia', 'Loker tersedia',
            'Kapasitas', 'Terisi', 'Santri', 'Pengawas', 'Direncanakan', 'Tersedia', '% hunian', 'Kelebihan muatan', 'Bed/loker tidak tersedia',
        ]));

        foreach ($current['dormitories'] as $dormitory) {
            foreach ($dormitory['buildings'] as $building) {
                foreach ($building['rooms'] as $room) {
                    $metrics = $room['metrics'];
                    $writer->addRow(Row::fromValues([
                        $dormitory['name'],
                        $building['name'].' ('.$building['code'].')',
                        $building['gender_label'],
                        $room['code'],
                        $room['counted'] ? 'Aktif' : 'Nonaktif',
                        $room['capacity_basis_label'],
                        $metrics['beds_available'].'/'.$metrics['beds_total'],
                        $metrics['lockers_available'].'/'.$metrics['lockers_total'],
                        $metrics['capacity'],
                        $metrics['active'],
                        $metrics['residents'],
                        $metrics['supervisors'],
                        $metrics['planned'],
                        $metrics['available'],
                        $metrics['percent'] ?? '–',
                        $metrics['over_capacity'] > 0 ? 'Ya' : '',
                        $metrics['unavailable'],
                    ]));
                }
            }
        }
    }

    /**
     * @param  list<EventRow>  $events
     */
    private function events(Writer $writer, array $events, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Riwayat');
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        self::widths($sheet, [12, 30, 10, 28, 22, 12, 30, 34]);

        $writer->addRow(self::header(['Tanggal', 'Nama', 'Kategori', 'Asrama', 'Gedung', 'Kamar', 'Kejadian', 'Keterangan']));

        foreach ($events as $event) {
            $writer->addRow(new Row([
                Cell::fromValue(CarbonImmutable::parse($event['date'])->toDateTimeImmutable(), (new Style)->withFormat('dd/mm/yyyy')),
                Cell::fromValue($event['name']),
                Cell::fromValue($event['category_label']),
                Cell::fromValue($event['dormitory']),
                Cell::fromValue($event['building']),
                Cell::fromValue($event['room']),
                Cell::fromValue($event['kind_label']),
                Cell::fromValue($event['detail'] ?? ''),
            ]));
        }

        if ($events === []) {
            $writer->addRow(Row::fromValues(['Tidak ada pergerakan '.self::day($from).' s.d. '.self::day($to).'.']));
        }
    }

    /**
     * @param  Metrics  $metrics
     * @return list<int|float|string>
     */
    private static function metricValues(array $metrics): array
    {
        return [
            $metrics['rooms'], $metrics['capacity'], $metrics['active'], $metrics['residents'], $metrics['supervisors'],
            $metrics['planned'], $metrics['available'], $metrics['percent'] ?? '–', $metrics['over_capacity'], $metrics['unavailable'],
        ];
    }

    /**
     * @param  Movement  $movement
     * @return list<int>
     */
    private static function movementValues(array $movement): array
    {
        return [
            $movement['start'], $movement['entries'], $movement['left_dormitory'], $movement['left_school'], $movement['year_end'],
            $movement['other'], $movement['mutation_in'], $movement['mutation_out'], $movement['emergency'], $movement['end'],
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private static function header(array $values): Row
    {
        return Row::fromValuesWithStyle($values, (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7')->withShouldWrapText(true));
    }

    /**
     * @param  list<int>  $widths
     */
    private static function widths(Sheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
    }

    private static function day(CarbonImmutable $date): string
    {
        return $date->format('d/m/Y');
    }
}
