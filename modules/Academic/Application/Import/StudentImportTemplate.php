<?php

namespace Modules\Academic\Application\Import;

use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Core\Domain\Organization\Organization;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Template impor siswa .xlsx untuk satu lembaga (PRD-002 AC-1e):
 *
 * - "Siswa"    : judul kolom + baris kosong yang kolom teksnya berformat Teks (agar 0 di depan
 *                NISN/HP tidak hilang) dan kolom tanggal berformat tanggal.
 * - "Petunjuk" : arti setiap kolom, kode yang sah, dan daftar kelas aktif tahun ajaran berjalan.
 */
final class StudentImportTemplate
{
    private const TEXT_FORMAT = '@';

    private const DATE_FORMAT = 'yyyy-mm-dd';

    public function __construct(
        private readonly StudentImportValidator $validator,
    ) {}

    public static function fileName(Organization $organization): string
    {
        return 'template-impor-siswa-'.mb_strtolower($organization->code).'.xlsx';
    }

    public function write(string $tenantId, Organization $organization, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->studentSheet($writer);
            $this->guideSheet($writer, $tenantId, $organization);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    private function studentSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Siswa');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $date = (new Style)->withFormat(self::DATE_FORMAT);

        $headerCells = [];
        $emptyCells = [];

        foreach (StudentImportColumns::all() as $index => $column) {
            $headerCells[] = Cell::fromValue($column['key'].($column['required'] ? ' *' : ''), $header);
            $emptyCells[] = Cell::fromValue(null, $column['text'] ? $text : $date);
            $sheet->setColumnWidth($column['key'] === StudentImportColumns::ADDRESS ? 40 : 18, $index + 1);
        }

        $writer->addRow(new Row($headerCells));

        // Baris kosong yang sudah berformat, sebanyak batas impor.
        for ($i = 0; $i < StudentImportReader::MAX_ROWS; $i++) {
            $writer->addRow(new Row($emptyCells));
        }
    }

    private function guideSheet(Writer $writer, string $tenantId, Organization $organization): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Petunjuk');
        $sheet->setColumnWidth(22, 1);
        $sheet->setColumnWidth(10, 2);
        $sheet->setColumnWidth(90, 3);

        $bold = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle(["Impor siswa untuk {$organization->name} ({$organization->code})"], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Kolom', 'Wajib', 'Keterangan'], $bold));

        foreach (StudentImportColumns::all() as $column) {
            $writer->addRow(Row::fromValues([$column['key'], $column['required'] ? 'Ya' : '', $column['label'].'. '.$column['hint']]));
        }

        $writer->addRow(Row::fromValues([]));

        foreach ([
            'Isi data mulai baris ke-2 lembar Siswa. Jangan ubah judul kolom; kolom bertanda * wajib diisi.',
            'Satu baris = satu siswa yang didaftarkan ke '.$organization->name.'. Lembaga lain memakai file tersendiri.',
            'Siswa yang sudah tercatat di yayasan (mis. santri MDA yang juga masuk SMP): isi NISN-nya; bila NISN & nama cocok, ia hanya didaftarkan ke lembaga ini.',
            'NIK siswa dan NIK ayah/ibu/wali opsional (16 angka, format Teks). NIK orang tua yang sudah tercatat = orang yang sama; NIK milik orang lain ditolak.',
            'Maksimal '.StudentImportReader::MAX_ROWS.' baris per file. Simpan hanya bisa bila tidak ada baris yang salah.',
            'Boleh juga disimpan sebagai CSV (pemisah koma atau titik koma) dengan judul kolom yang sama.',
        ] as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Kode', 'Isi', 'Arti'], $bold));
        $writer->addRow(Row::fromValues(['jenis_kelamin', 'L', 'Laki-laki']));
        $writer->addRow(Row::fromValues(['jenis_kelamin', 'P', 'Perempuan']));

        foreach (EntryType::cases() as $type) {
            $writer->addRow(Row::fromValues(['jenis_masuk', $type->value, $type->label()]));
        }

        foreach (['AYAH' => 'Ayah', 'IBU' => 'Ibu', 'WALI' => 'Wali'] as $code => $label) {
            $writer->addRow(Row::fromValues(['kontak_utama', $code, $label]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Kelas aktif tahun ajaran berjalan', 'Tingkat', ''], $bold));

        $classes = array_filter($this->validator->classes($tenantId, $organization), fn (SchoolClass $class): bool => $class->is_active);

        if ($classes === []) {
            $writer->addRow(Row::fromValues(['(belum ada kelas; kosongkan kolom kelas)']));
        }

        foreach ($classes as $class) {
            $writer->addRow(Row::fromValues([$class->name, (string) $class->grade]));
        }
    }
}
