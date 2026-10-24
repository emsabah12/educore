<?php

namespace Modules\Academic\Application\Teaching;

use Carbon\CarbonImmutable;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\Core\Support\BusinessClock;

/**
 * Rekap presensi mengajar bulanan per lembaga per guru (PRD-002 §20.4, AC-6c). Hanya membaca.
 *
 * [ASUMSI]:
 * - Dihitung sampai hari ini (bulan berjalan) — pertemuan yang belum terjadi tidak dihitung.
 * - Guru sebuah pertemuan = pengampu yang tercatat; bila belum dicatat, pengampu sekarang.
 *   Pertemuan tanpa pengampu dikumpulkan di baris "(tanpa pengampu)".
 * - Menggantikan = jumlah pertemuan di lembaga ini yang ia gantikan; guru yang hanya menggantikan
 *   ikut tampil.
 * - Persentase mengajar = Mengajar ÷ Terjadwal.
 * - Hak akses (`academic.teaching.manage` di lembaga) dicek pemanggil.
 *
 * @phpstan-import-type Lesson from Lessons
 *
 * @phpstan-type RecapRow array{employee_id: string|null, name: string, scheduled: int, taught: int, substituted: int, empty: int, unrecorded: int, substituting: int, percent: float|null}
 */
final class TeachingRecap
{
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly Lessons $lessons,
    ) {}

    /**
     * "2026-10" bila sah dan tidak di masa depan; selain itu bulan berjalan.
     */
    public static function month(mixed $requested): string
    {
        $current = BusinessClock::today()->format('Y-m');

        if (is_string($requested) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $requested) === 1 && $requested <= $current) {
            return $requested;
        }

        return $current;
    }

    public static function monthLabel(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));

        return self::MONTHS[$number].' '.$year;
    }

    /**
     * @return array{month: string, label: string, start: string, until: string|null, rows: list<RecapRow>, totals: RecapRow, lessons: list<Lesson>}
     */
    public function build(string $tenantId, string $organizationId, string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth();
        $today = BusinessClock::today();
        $until = $end->toDateString() < $today->toDateString() ? $end : $today;

        /** @var array<string, RecapRow> $rows */
        $rows = [];
        $lessons = [];

        for ($day = $start; $day->toDateString() <= $until->toDateString(); $day = $day->addDay()) {
            foreach ($this->lessons->forOrganization($tenantId, $organizationId, $day->toDateString())['lessons'] as $lesson) {
                $lessons[] = $lesson;
                $teacher = $lesson['teacher'];
                $key = $teacher['id'] ?? '';
                $rows[$key] ??= self::emptyRow($teacher['id'] ?? null, $teacher['name'] ?? '(tanpa pengampu)');
                $rows[$key]['scheduled']++;

                $status = $lesson['record']['status'] ?? null;

                match ($status) {
                    TeachingStatus::Taught->value => $rows[$key]['taught']++,
                    TeachingStatus::Substituted->value => $rows[$key]['substituted']++,
                    TeachingStatus::Empty->value => $rows[$key]['empty']++,
                    default => $rows[$key]['unrecorded']++,
                };

                $substitute = $lesson['record']['substitute'] ?? null;

                if ($status === TeachingStatus::Substituted->value && $substitute !== null) {
                    $rows[$substitute['id']] ??= self::emptyRow($substitute['id'], $substitute['name']);
                    $rows[$substitute['id']]['substituting']++;
                }
            }
        }

        $list = array_values(array_map(fn (array $row): array => self::withPercent($row), $rows));
        usort($list, fn (array $a, array $b): int => [$a['employee_id'] === null, mb_strtolower($a['name'])] <=> [$b['employee_id'] === null, mb_strtolower($b['name'])]);

        $totals = self::emptyRow(null, 'Jumlah');

        foreach ($list as $row) {
            foreach (['scheduled', 'taught', 'substituted', 'empty', 'unrecorded', 'substituting'] as $field) {
                $totals[$field] += $row[$field];
            }
        }

        return [
            'month' => $month,
            'label' => self::monthLabel($month),
            'start' => $start->toDateString(),
            // null = bulan belum dimulai (tidak mungkin: bulan masa depan ditolak), selain itu batas hitung.
            'until' => $until->toDateString() >= $start->toDateString() ? $until->toDateString() : null,
            'rows' => $list,
            'totals' => self::withPercent($totals),
            'lessons' => $lessons,
        ];
    }

    /**
     * @return RecapRow
     */
    private static function emptyRow(?string $employeeId, string $name): array
    {
        return [
            'employee_id' => $employeeId,
            'name' => $name,
            'scheduled' => 0,
            'taught' => 0,
            'substituted' => 0,
            'empty' => 0,
            'unrecorded' => 0,
            'substituting' => 0,
            'percent' => null,
        ];
    }

    /**
     * @param  RecapRow  $row
     * @return RecapRow
     */
    private static function withPercent(array $row): array
    {
        $row['percent'] = $row['scheduled'] === 0 ? null : round($row['taught'] / $row['scheduled'] * 100, 1);

        return $row;
    }
}
