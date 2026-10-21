<?php

namespace Modules\Academic\Application\Classroom;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\GuardianRelation;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Ekspor daftar siswa satu kelas ke .xlsx (PRD-002 §18 P1).
 *
 * Kolom dasar: No, Nama, L/P, NISN, Nomor induk, Tanggal masuk kelas — siswa yang masih di kelas,
 * urut nama. Tambahan sesuai hak pemanggil:
 * - $withPersonal (izin lihat data pribadi): tempat & tanggal lahir;
 * - $withContacts (izin lihat data pribadi, atau wali kelas): kontak utama orang tua/wali.
 * NISN, nomor induk, dan HP ditulis sebagai teks agar 0 di depan tidak hilang.
 */
final class ClassRosterExport
{
    private const TEXT_FORMAT = '@';

    public static function fileName(SchoolClass $class): string
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->value('name');

        return 'daftar-siswa-'.Str::slug($class->name).'-'.Str::slug(is_string($year) ? $year : '').'.xlsx';
    }

    public function write(SchoolClass $class, string $path, bool $withPersonal, bool $withContacts): void
    {
        $header = ['No', 'Nama', 'L/P', 'NISN', 'Nomor induk', 'Tanggal masuk kelas'];

        if ($withPersonal) {
            $header = array_merge($header, ['Tempat lahir', 'Tanggal lahir']);
        }

        if ($withContacts) {
            $header = array_merge($header, ['Kontak utama', 'Hubungan', 'HP kontak utama']);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Siswa');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            foreach ([5, 32, 6, 14, 14, 14, 18, 14, 28, 12, 16] as $index => $width) {
                if ($index < count($header)) {
                    $sheet->setColumnWidth($width, $index + 1);
                }
            }

            $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7')));
            $text = (new Style)->withFormat(self::TEXT_FORMAT);

            foreach ($this->rows($class->id, $withContacts) as $index => $row) {
                $cells = [
                    Cell::fromValue($index + 1),
                    Cell::fromValue($row['name']),
                    Cell::fromValue($row['gender'] ?? ''),
                    Cell::fromValue($row['nisn'] ?? '', $text),
                    Cell::fromValue($row['student_number'] ?? '', $text),
                    Cell::fromValue($row['start_date']),
                ];

                if ($withPersonal) {
                    $cells[] = Cell::fromValue($row['birth_place'] ?? '');
                    $cells[] = Cell::fromValue($row['birth_date'] ?? '');
                }

                if ($withContacts) {
                    $cells[] = Cell::fromValue($row['contact_name'] ?? '');
                    $cells[] = Cell::fromValue($row['contact_relation'] ?? '');
                    $cells[] = Cell::fromValue($row['contact_phone'] ?? '', $text);
                }

                $writer->addRow(new Row($cells));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @return list<array{name: string, gender: string|null, nisn: string|null, student_number: string|null, start_date: string, birth_place: string|null, birth_date: string|null, contact_name: string|null, contact_relation: string|null, contact_phone: string|null}>
     */
    private function rows(string $classId, bool $withContacts): array
    {
        $query = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->whereNull('cm.end_date')
            ->orderBy('p.name')
            ->orderBy('s.id');

        $columns = ['p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 's.nisn', 'e.student_number', 'cm.start_date'];

        if ($withContacts) {
            $query->leftJoin('ac_student_guardians as g', function (JoinClause $join): void {
                $join->on('g.student_id', '=', 's.id')->where('g.is_primary_contact', true);
            })
                ->leftJoin('memberships as gm', 'gm.id', '=', 'g.guardian_membership_id')
                ->leftJoin('persons as gp', 'gp.id', '=', 'gm.person_id');
            $columns = array_merge($columns, ['g.relation', 'gp.name as contact_name', 'gp.phone as contact_phone']);
        }

        $rows = [];

        foreach ($query->get($columns) as $row) {
            $relation = $withContacts && ($row->relation ?? null) !== null ? GuardianRelation::tryFrom((string) $row->relation) : null;

            $rows[] = [
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'birth_place' => $row->birth_place === null ? null : (string) $row->birth_place,
                'birth_date' => $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10),
                'contact_name' => $withContacts && ($row->contact_name ?? null) !== null ? (string) $row->contact_name : null,
                'contact_relation' => $relation?->label(),
                'contact_phone' => $withContacts && ($row->contact_phone ?? null) !== null ? (string) $row->contact_phone : null,
            ];
        }

        return $rows;
    }
}
