<?php

namespace Modules\Dormitory\Application\Import;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Placement\RoomOccupancy;
use Modules\Dormitory\Domain\Facility\Dormitory;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Template impor penempatan .xlsx untuk satu asrama (PRD-008 §20.2), pola template impor siswa:
 *
 * - "Penempatan": judul kolom + baris kosong; kolom teks berformat Teks (agar 0 di depan NISN/NIS tidak hilang),
 *                 kolom tanggal berformat tanggal.
 * - "Petunjuk"  : arti kolom dan daftar gedung → kamar aktif (putra/putri, sisa slot santri, bed/loker kosong).
 */
final class PlacementImportTemplate
{
    private const TEXT_FORMAT = '@';

    private const DATE_FORMAT = 'yyyy-mm-dd';

    public function __construct(
        private readonly FacilityDirectory $facilities,
    ) {}

    public static function fileName(Dormitory $dormitory): string
    {
        return 'template-impor-penempatan-'.mb_strtolower($dormitory->code).'.xlsx';
    }

    public function write(Dormitory $dormitory, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->placementSheet($writer);
            $this->guideSheet($writer, $dormitory);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    private function placementSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Penempatan');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $date = (new Style)->withFormat(self::DATE_FORMAT);
        $headerCells = [];
        $emptyCells = [];

        foreach (PlacementImportColumns::all() as $index => $column) {
            $headerCells[] = Cell::fromValue($column['key'].($column['required'] ? ' *' : ''), $header);
            $emptyCells[] = Cell::fromValue(null, $column['text'] ? $text : $date);
            $sheet->setColumnWidth($column['key'] === PlacementImportColumns::NOTE ? 36 : 16, $index + 1);
        }

        $writer->addRow(new Row($headerCells));

        for ($i = 0; $i < PlacementImportReader::MAX_ROWS; $i++) {
            $writer->addRow(new Row($emptyCells));
        }
    }

    private function guideSheet(Writer $writer, Dormitory $dormitory): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Petunjuk');
        $sheet->setColumnWidth(16, 1);
        $sheet->setColumnWidth(14, 2);
        $sheet->setColumnWidth(12, 3);
        $sheet->setColumnWidth(14, 4);
        $sheet->setColumnWidth(50, 5);
        $sheet->setColumnWidth(50, 6);
        $bold = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle(["Impor penempatan santri untuk {$dormitory->name} ({$dormitory->code})"], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Kolom', 'Wajib', 'Keterangan'], $bold));

        foreach (PlacementImportColumns::all() as $column) {
            $writer->addRow(Row::fromValues([$column['key'], $column['required'] ? 'Ya' : '', $column['label'].'. '.$column['hint']]));
        }

        $writer->addRow(Row::fromValues([]));

        foreach ([
            'Isi data mulai baris ke-2 lembar Penempatan. Jangan ubah judul kolom; kolom bertanda * wajib diisi.',
            'Satu baris = satu santri. Hanya santri yang terdaftar aktif di lembaga milik asrama ini dan jenis kelaminnya sesuai gedung.',
            'Aturan sama dengan tombol Tempatkan: slot kamar, bed/loker, tanggal, dan penempatan yang sudah ada diperiksa berurutan.',
            'Maksimal '.PlacementImportReader::MAX_ROWS.' baris per file. Simpan hanya bisa bila tidak ada baris yang salah (semua atau tidak).',
            'Pengawas tidak diimpor; tempatkan lewat halaman kamar.',
        ] as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Kode gedung', 'Kode kamar', 'Putra/Putri', 'Sisa slot santri', 'Bed kosong', 'Loker kosong'], $bold));

        $rooms = $this->facilities->roomsMatching(fn (Builder $query): Builder => $query
            ->join('dm_buildings as b', 'b.id', '=', 'r.building_id')
            ->where('r.dormitory_id', $dormitory->id)
            ->where('r.is_active', true)
            ->where('b.is_active', true)
            ->reorder()
            ->orderBy('b.code')
            ->orderBy('r.code'));

        if ($rooms === []) {
            $writer->addRow(Row::fromValues(['(belum ada kamar aktif)']));
        }

        $buildings = DB::table('dm_buildings')->where('dormitory_id', $dormitory->id)->get(['id', 'code', 'gender'])->keyBy('id');

        foreach ($rooms as $room) {
            $building = $buildings[$room['building_id']] ?? null;
            $occupancy = RoomOccupancy::forRoom($room['id']);
            $free = max(0, min(
                $room['capacity']['resident_slots'] - $room['occupancy']['residents'],
                $room['capacity']['effective'] - $room['occupancy']['active'] - $room['occupancy']['planned'],
            ));
            $codes = fn (string $table, array $held): string => implode(', ', array_map('strval', DB::table($table)
                ->where('room_id', $room['id'])->where('is_active', true)->where('status', 'TERSEDIA')
                ->whereNotIn('id', $held)->orderBy('code')->pluck('code')->all()));

            $writer->addRow(Row::fromValues([
                $building === null ? '' : (string) $building->code,
                $room['code'],
                $building !== null && $building->gender === 'P' ? 'Putri' : 'Putra',
                $room['over_capacity'] ? 0 : $free,
                $codes('dm_beds', array_keys($occupancy['beds'])),
                $codes('dm_lockers', array_keys($occupancy['lockers'])),
            ]));
        }
    }
}
