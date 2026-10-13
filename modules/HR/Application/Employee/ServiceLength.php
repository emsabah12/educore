<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;

/**
 * Masa kerja dari periode-periode kepegawaian (PRD-001 §6).
 *
 * Periode yang bersambung (mis. Kontrak berakhir 30 Juni, Tetap mulai 1 Juli) atau tumpang
 * tindih digabung dulu, baru dihitung bulan penuhnya. Periode yang masih berjalan dihitung
 * sampai hari ini (tanggal yayasan).
 */
final class ServiceLength
{
    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable|null}>  $periods
     * @return array{years: int, months: int, label: string}
     */
    public static function of(array $periods, CarbonImmutable $today): array
    {
        usort($periods, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $totalMonths = 0;
        $spanStart = null;
        $spanEnd = null;

        foreach ($periods as $period) {
            $end = $period['end'] ?? $today;

            if ($end->lessThan($period['start'])) {
                continue;
            }

            // Bersambung bila mulai paling lambat sehari setelah rentang sebelumnya berakhir.
            if ($spanStart !== null && $spanEnd !== null && $period['start']->lessThanOrEqualTo($spanEnd->addDay())) {
                $spanEnd = $end->greaterThan($spanEnd) ? $end : $spanEnd;

                continue;
            }

            if ($spanStart !== null && $spanEnd !== null) {
                $totalMonths += self::fullMonths($spanStart, $spanEnd);
            }

            $spanStart = $period['start'];
            $spanEnd = $end;
        }

        if ($spanStart !== null && $spanEnd !== null) {
            $totalMonths += self::fullMonths($spanStart, $spanEnd);
        }

        $years = intdiv($totalMonths, 12);
        $months = $totalMonths % 12;

        return [
            'years' => $years,
            'months' => $months,
            'label' => self::label($years, $months),
        ];
    }

    /**
     * Bulan penuh dari $start sampai $end (inklusif): 1 Juli 2020 – 30 Juni 2025 = 60 bulan.
     */
    private static function fullMonths(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $endExclusive = $end->addDay();
        $months = ($endExclusive->year - $start->year) * 12 + ($endExclusive->month - $start->month);

        if ($endExclusive->day < $start->day) {
            $months--;
        }

        return max(0, $months);
    }

    private static function label(int $years, int $months): string
    {
        if ($years === 0 && $months === 0) {
            return 'Kurang dari 1 bulan';
        }

        $parts = [];

        if ($years > 0) {
            $parts[] = "{$years} tahun";
        }

        if ($months > 0) {
            $parts[] = "{$months} bulan";
        }

        return implode(' ', $parts);
    }
}
