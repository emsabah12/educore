<?php

namespace Modules\Academic\Application\Classroom;

/**
 * Isian satu baris jadwal (PRD-002 §16 AC-3c): mapel kelas, hari (1 Senin … 7 Ahad), jam mulai–selesai.
 * Jam dalam format "HH:MM"; null = kosong atau tidak sah.
 */
final readonly class ScheduleData
{
    public function __construct(
        public string $classSubjectId,
        public int $day,
        public ?string $start,
        public ?string $end,
    ) {}

    /**
     * "7:05", "07:05" atau "07:05:00" → "07:05"; selain itu null.
     */
    public static function cleanTime(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
