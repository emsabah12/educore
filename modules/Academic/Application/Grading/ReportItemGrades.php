<?php

namespace Modules\Academic\Application\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Classroom\ManageClasses;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\ReportItem;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Nilai ekstrakurikuler & kepribadian per kelas per semester (PRD-002 §22.6 AC-8d).
 *
 * Aturan [ASUMSI disetujui owner 2026-11-11]:
 * - Huruf dari skala predikat lembaga (AC-8b); ekstrakurikuler tanpa huruf = tidak ikut ("-").
 * - Kepribadian: keterangan otomatis dari butir per huruf; keterangan pengganti per siswa opsional
 *   (maks. 100).
 * - Diisi wali kelas & pengelola kelas (dicek pemanggil); kelas tahun lewat hanya dibaca.
 * - Butir yang ditampilkan: butir aktif + butir nonaktif yang sudah dinilai di kelas & semester itu.
 *
 * @phpstan-import-type TermOption from ManageGrades
 *
 * @phpstan-type ItemOption array{id: string, kind: string, name: string, descriptions: array<string, string>}
 * @phpstan-type ItemGrade array{letter: string|null, note: string|null}
 */
final class ReportItemGrades
{
    public const MAX_STUDENTS = 200;

    public function __construct(
        private readonly TermRoster $roster,
        private readonly GradingSetting $grading,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Butir yang dipakai kelas & semester ini, urut jenis → urutan → nama.
     *
     * @return list<ItemOption>
     */
    public function items(SchoolClass $class, string $termId): array
    {
        $graded = DB::table('ac_report_item_grades')->where('class_id', $class->id)->where('term_id', $termId)->distinct()->pluck('item_id')->map(fn (mixed $id): string => (string) $id)->all();
        $items = [];

        foreach (ReportItem::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $class->organization_id)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $graded))
            ->orderByRaw("kind = 'KEPRIBADIAN'")
            ->orderBy('sort_order')
            ->orderByRaw('lower(name)')
            ->get() as $item) {
            $items[] = ['id' => $item->id, 'kind' => $item->kind, 'name' => $item->name, 'descriptions' => $item->descriptions ?? []];
        }

        return $items;
    }

    /**
     * Nilai tersimpan: id pendaftaran → id butir → huruf & keterangan pengganti.
     *
     * @return array<string, array<string, ItemGrade>>
     */
    public function grades(string $classId, string $termId): array
    {
        $grades = [];

        foreach (DB::table('ac_report_item_grades')->where('class_id', $classId)->where('term_id', $termId)->get(['enrollment_id', 'item_id', 'letter', 'note']) as $row) {
            $grades[(string) $row->enrollment_id][(string) $row->item_id] = [
                'letter' => $row->letter === null ? null : (string) $row->letter,
                'note' => $row->note === null ? null : (string) $row->note,
            ];
        }

        return $grades;
    }

    /**
     * Isi halaman: butir, skala huruf, siswa, nilai.
     *
     * @param  TermOption  $term
     * @return array{items: list<ItemOption>, predicates: list<array{letter: string, label: string|null, min: int}>, students: list<array{enrollment_id: string, name: string, left: bool}>, grades: array<string, array<string, ItemGrade>>}
     */
    public function sheet(SchoolClass $class, array $term): array
    {
        $students = [];

        foreach ($this->roster->forClass($class->id, $term, []) as $student) {
            $students[] = ['enrollment_id' => $student['enrollment_id'], 'name' => $student['name'], 'left' => $student['left']];
        }

        return [
            'items' => $this->items($class, $term['value']),
            'predicates' => $this->grading->rulesFor($class->tenant_id, $class->organization_id)->predicates,
            'students' => $students,
            'grades' => $this->grades($class->id, $term['value']),
        ];
    }

    /**
     * Simpan nilai siswa yang dikirim (siswa/butir lain tidak diubah); huruf & keterangan kosong = dihapus.
     *
     * @param  TermOption  $term
     * @param  array<string, mixed>  $entries  id pendaftaran → id butir → {letter, note}
     * @return int jumlah siswa yang dikirim
     *
     * @throws GradingException
     */
    public function save(SchoolClass $class, array $term, array $entries): int
    {
        if ($entries === []) {
            throw GradingException::make('grades', 'Belum ada nilai yang diisi.');
        }

        if (count($entries) > self::MAX_STUDENTS) {
            throw GradingException::make('grades', 'Paling banyak '.self::MAX_STUDENTS.' siswa sekali simpan.');
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        if (ManageClasses::isPastYear($year)) {
            throw GradingException::make('form', 'Tahun ajaran kelas ini sudah lewat; nilainya hanya bisa dilihat.');
        }

        $roster = [];

        foreach ($this->roster->forClass($class->id, $term, []) as $student) {
            $roster[$student['enrollment_id']] = $student['name'];
        }

        if (array_diff_key($entries, $roster) !== []) {
            throw GradingException::make('grades', 'Daftar siswa berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
        }

        $items = array_column($this->items($class, $term['value']), 'kind', 'id');
        $letters = $this->grading->rulesFor($class->tenant_id, $class->organization_id)->letters();
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($class, $term, $entries, $roster, $items, $letters, $now): void {
            foreach ($entries as $enrollmentId => $values) {
                foreach (is_array($values) ? $values : [] as $itemId => $value) {
                    $itemId = (string) $itemId;

                    if (! isset($items[$itemId])) {
                        throw GradingException::make('grades', 'Daftar ekstrakurikuler/kepribadian berubah. Muat ulang halaman.');
                    }

                    $value = is_array($value) ? $value : [];
                    $letter = is_string($value['letter'] ?? null) ? mb_strtoupper(trim($value['letter'])) : '';
                    $note = $items[$itemId] === ReportItem::PERSONALITY && is_string($value['note'] ?? null)
                        ? trim((string) preg_replace('/\s+/u', ' ', $value['note']))
                        : '';

                    if ($letter !== '' && ! in_array($letter, $letters, true)) {
                        throw GradingException::make('grades', "Nilai untuk {$roster[$enrollmentId]} harus salah satu dari: ".implode(', ', $letters).'.');
                    }

                    if (mb_strlen($note) > ReportItems::DESCRIPTION_MAX) {
                        throw GradingException::make('grades', "Keterangan untuk {$roster[$enrollmentId]} maksimal ".ReportItems::DESCRIPTION_MAX.' karakter.');
                    }

                    $key = ['class_id' => $class->id, 'term_id' => $term['value'], 'enrollment_id' => $enrollmentId, 'item_id' => $itemId];

                    if ($letter === '' && $note === '') {
                        DB::table('ac_report_item_grades')->where($key)->delete();

                        continue;
                    }

                    $updated = DB::table('ac_report_item_grades')->where($key)->update([
                        'letter' => $letter === '' ? null : $letter,
                        'note' => $note === '' ? null : $note,
                        'updated_at' => $now,
                    ]);

                    if ($updated === 0) {
                        DB::table('ac_report_item_grades')->insert($key + [
                            'id' => (string) Str::uuid7(),
                            'tenant_id' => $class->tenant_id,
                            'organization_id' => $class->organization_id,
                            'letter' => $letter === '' ? null : $letter,
                            'note' => $note === '' ? null : $note,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            $this->audit->record(
                AcademicAuditAction::REPORT_ITEMS_GRADED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'semester' => $term['label'], 'siswa' => count($entries)],
                organizationId: $class->organization_id,
                tenantId: $class->tenant_id,
            );
        });

        return count($entries);
    }

    /**
     * Keterangan kepribadian yang dicetak: pengganti per siswa, atau keterangan otomatis butir untuk hurufnya.
     *
     * @param  array<string, string>  $descriptions
     */
    public static function personalityText(array $descriptions, ?string $letter, ?string $note): ?string
    {
        if ($note !== null && $note !== '') {
            return $note;
        }

        return $letter === null ? null : ($descriptions[$letter] ?? null);
    }
}
