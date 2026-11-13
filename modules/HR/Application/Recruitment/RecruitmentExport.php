<?php

namespace Modules\HR\Application\Recruitment;

use Modules\HR\Domain\Recruitment\ApplicantStage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan .xlsx rekap satu lowongan (PRD-001 §20.2, HR-9b): lembar **Ringkasan** (jumlah per tahap termasuk yang
 * sudah dihapus otomatis, diterima vs dibutuhkan) dan **Pelamar** (tanpa HP, email, catatan, dan isi catatan seleksi).
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type VacancyRow from RecruitmentRecords
 * @phpstan-import-type ApplicantRow from RecruitmentRecords
 */
final class RecruitmentExport
{
    /**
     * @param  VacancyRow  $vacancy
     * @param  list<ApplicantRow>  $applicants
     */
    public function write(array $vacancy, array $applicants, string $generatedOn, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Ringkasan');

            foreach ([28, 14, 22] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle(["Rekap lowongan: {$vacancy['title']}"], $bold));
            $writer->addRow(Row::fromValues(['Lembaga', $vacancy['organization']]));
            $writer->addRow(Row::fromValues(['Jabatan', $vacancy['position']]));
            $writer->addRow(Row::fromValues(['Status', $vacancy['status_label']]));
            $writer->addRow(Row::fromValues(['Dibutuhkan', $vacancy['openings']]));
            $writer->addRow(Row::fromValues(['Diterima', $vacancy['counts'][ApplicantStage::Accepted->value] + $vacancy['purged'][ApplicantStage::Accepted->value]]));
            $writer->addRow(Row::fromValues(['Sudah dijadikan pegawai', $vacancy['hired']]));
            $writer->addRow(Row::fromValues(['Dibuat per', $generatedOn]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(['Tahap', 'Jumlah', 'Termasuk dihapus otomatis'], $header));

            foreach (ApplicantStage::cases() as $stage) {
                $purged = $vacancy['purged'][$stage->value];
                $writer->addRow(Row::fromValues([$stage->label(), $vacancy['counts'][$stage->value] + $purged, $purged]));
            }

            $writer->addRow(Row::fromValuesWithStyle(['Jumlah', $vacancy['total'] + $vacancy['purged_total'], $vacancy['purged_total']], $bold));

            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Pelamar');
            $sheet->setSheetView(new SheetView(freezeRow: 1));

            foreach ([6, 30, 8, 22, 28, 18, 12, 14, 16] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle(['No', 'Nama', 'L/P', 'Pendidikan', 'Jurusan', 'Tahap', 'Nilai terakhir', 'Ditambahkan', 'Jadi pegawai'], $header));

            foreach ($applicants as $index => $applicant) {
                $scores = array_values(array_filter(array_column($applicant['steps'], 'score'), fn ($score): bool => $score !== null));

                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $applicant['name'],
                    $applicant['gender'],
                    $applicant['education_label'] ?? '',
                    $applicant['education_major'] ?? '',
                    $applicant['stage_label'],
                    $scores === [] ? '' : $scores[count($scores) - 1],
                    $applicant['added_on'],
                    $applicant['employee_id'] === null ? 'Belum' : 'Ya',
                ]));
            }
        } finally {
            $writer->close();
        }
    }
}
