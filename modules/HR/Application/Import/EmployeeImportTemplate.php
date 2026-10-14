<?php

namespace Modules\HR\Application\Import;

use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Person\Gender;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Template impor pegawai .xlsx (PRD-001 §6.2):
 *
 * - "Pegawai"   : judul kolom + baris kosong yang kolom teksnya sudah berformat Teks
 *                 (agar 0 di depan NUPTK/HP tidak hilang) dan kolom tanggal berformat tanggal.
 * - "Petunjuk"  : arti setiap kolom.
 * - "Kode"      : kode yang sah di yayasan ini (hanya yang aktif).
 */
final class EmployeeImportTemplate
{
    public const FILE_NAME = 'template-impor-pegawai.xlsx';

    private const TEXT_FORMAT = '@';

    private const DATE_FORMAT = 'yyyy-mm-dd';

    public function write(string $tenantId, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->employeeSheet($writer);
            $this->guideSheet($writer);
            $this->codeSheet($writer, $tenantId);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    private function employeeSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Pegawai');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $columns = EmployeeImportColumns::all();
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $date = (new Style)->withFormat(self::DATE_FORMAT);

        $headerCells = [];
        $emptyCells = [];

        foreach ($columns as $index => $column) {
            $headerCells[] = Cell::fromValue($column['key'].($column['required'] ? ' *' : ''), $header);
            $emptyCells[] = Cell::fromValue(null, $column['text'] ? $text : $date);
            $sheet->setColumnWidth($column['key'] === EmployeeImportColumns::ADDRESS ? 40 : 20, $index + 1);
        }

        $writer->addRow(new Row($headerCells));

        // Baris kosong yang sudah berformat, sebanyak batas impor.
        for ($i = 0; $i < EmployeeImportReader::MAX_ROWS; $i++) {
            $writer->addRow(new Row($emptyCells));
        }
    }

    private function guideSheet(Writer $writer): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Petunjuk');
        $sheet->setColumnWidth(22, 1);
        $sheet->setColumnWidth(10, 2);
        $sheet->setColumnWidth(80, 3);

        $bold = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle(['Kolom', 'Wajib', 'Keterangan'], $bold));

        foreach (EmployeeImportColumns::all() as $column) {
            $writer->addRow(Row::fromValues([$column['key'], $column['required'] ? 'Ya' : '', $column['label'].'. '.$column['hint']]));
        }

        $writer->addRow(Row::fromValues([]));

        foreach ([
            'Isi data mulai baris ke-2 lembar Pegawai. Jangan ubah judul kolom; kolom bertanda * wajib diisi.',
            'Satu baris = satu pegawai BARU beserta penempatan utamanya. Pegawai yang sudah ada tidak diubah.',
            'Maksimal '.EmployeeImportReader::MAX_ROWS.' baris per file. Simpan hanya bisa bila tidak ada baris yang salah.',
            'Kode jenis pegawai, status kepegawaian, jabatan, lembaga, dan pendidikan ada di lembar Kode.',
            'Boleh juga disimpan sebagai CSV (pemisah koma atau titik koma) dengan judul kolom yang sama.',
        ] as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }
    }

    private function codeSheet(Writer $writer, string $tenantId): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Kode');

        /** @var list<array{title: string, rows: list<array{0: string, 1: string}>}> $groups */
        $groups = [
            ['title' => 'jenis_pegawai', 'rows' => $this->catalogRows(CatalogKind::EmploymentType, $tenantId)],
            ['title' => 'status_kepegawaian', 'rows' => $this->catalogRows(CatalogKind::Classification, $tenantId)],
            ['title' => 'jabatan', 'rows' => $this->catalogRows(CatalogKind::Position, $tenantId)],
            ['title' => 'kode_lembaga', 'rows' => $this->organizationRows($tenantId)],
            ['title' => 'pendidikan', 'rows' => array_map(fn (EducationLevel $level): array => [$level->value, $level->label()], EducationLevel::cases())],
            ['title' => 'jenis_kelamin', 'rows' => array_map(fn (Gender $gender): array => [$gender->value, $gender->label()], Gender::cases())],
        ];

        // Tiap kelompok dua kolom (kode, nama) berdampingan, dipisah satu kolom kosong.
        $bold = (new Style)->withFontBold(true);
        $header = [];
        $height = 0;

        foreach ($groups as $index => $group) {
            $header[$index * 3] = Cell::fromValue($group['title'], $bold);
            $header[$index * 3 + 1] = Cell::fromValue('nama', $bold);
            $sheet->setColumnWidth(18, $index * 3 + 1);
            $sheet->setColumnWidth(36, $index * 3 + 2);
            $height = max($height, count($group['rows']));
        }

        $writer->addRow(new Row($header));

        for ($line = 0; $line < $height; $line++) {
            $cells = [];

            foreach ($groups as $index => $group) {
                if (isset($group['rows'][$line])) {
                    $cells[$index * 3] = Cell::fromValue($group['rows'][$line][0]);
                    $cells[$index * 3 + 1] = Cell::fromValue($group['rows'][$line][1]);
                }
            }

            $writer->addRow(new Row($cells));
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function catalogRows(CatalogKind $kind, string $tenantId): array
    {
        $rows = [];

        foreach ($kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('code')->get() as $item) {
            $rows[] = [$item->code, $item->name];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function organizationRows(string $tenantId): array
    {
        $rows = [];

        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('code')
            ->get();

        foreach ($organizations as $organization) {
            $rows[] = [$organization->code, $organization->name];
        }

        return $rows;
    }
}
