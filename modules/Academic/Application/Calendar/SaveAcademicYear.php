<?php

namespace Modules\Academic\Application\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Academic\Domain\Calendar\Holiday;
use Modules\Academic\Domain\Calendar\Term;
use Modules\Core\Application\Audit\AuditLogger;
use Throwable;

/**
 * Menambah dan mengubah tahun ajaran beserta semesternya dalam satu simpan (PRD-002 §4.4).
 *
 * Aturan:
 * - nama `TTTT/TTTT`, tahun kedua = tahun pertama + 1 (juga untuk tahun Hijriah, mis. 1447/1448);
 * - tanggal selesai sesudah tanggal mulai, paling lama 18 bulan;
 * - tidak tumpang tindih dengan tahun ajaran lain di kalender yang sama;
 * - 1–4 semester, berurutan, tidak tumpang tindih, dan di dalam rentang tahun ajaran;
 * - saat diubah, hari liburnya (§18 P4) tetap di dalam rentang tanggal yang baru.
 *
 * Tahun ajaran pertama di sebuah kalender otomatis menjadi tahun berjalan [ASUMSI].
 * Perubahan di satu kalender diantrekan dengan mengunci baris kalendernya.
 */
final class SaveAcademicYear
{
    public const NAME_PATTERN = '/^(\d{4})\/(\d{4})$/';

    public const MAX_MONTHS = 18;

    public const MAX_TERMS = 4;

    public const TERM_NAME_MAX_LENGTH = 50;

    public function __construct(
        private readonly ManageCalendars $calendars,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws CalendarException
     */
    public function create(string $tenantId, string $calendarId, AcademicYearData $data): AcademicYear
    {
        $checked = $this->check($data);

        try {
            return DB::transaction(function () use ($tenantId, $calendarId, $checked): AcademicYear {
                $calendar = $this->calendars->lock($tenantId, $calendarId);
                $this->assertFitsCalendar($calendar, $checked, null);

                $hasCurrent = AcademicYear::query()
                    ->withoutGlobalScope('tenant')
                    ->where('calendar_id', $calendar->id)
                    ->where('is_current', true)
                    ->exists();

                $year = new AcademicYear;
                $year->tenant_id = $tenantId;
                $year->calendar_id = $calendar->id;
                $year->name = $checked['name'];
                $year->start_date = $checked['start'];
                $year->end_date = $checked['end'];
                $year->is_current = ! $hasCurrent;
                $year->save();

                $this->syncTerms($year, $checked['terms']);

                $this->audit->record(
                    AcademicAuditAction::YEAR_CREATED,
                    AcademicAuditAction::SUBJECT_YEAR,
                    $year->id,
                    [
                        'kalender' => $calendar->name,
                        'name' => $year->name,
                        'start_date' => $checked['start']->toDateString(),
                        'end_date' => $checked['end']->toDateString(),
                        'semester' => count($checked['terms']),
                        'is_current' => $year->is_current,
                    ],
                    tenantId: $tenantId,
                );

                return $year;
            });
        } catch (UniqueConstraintViolationException) {
            throw CalendarException::duplicateYearName($checked['name']);
        }
    }

    /**
     * @throws CalendarException
     */
    public function update(string $tenantId, string $yearId, AcademicYearData $data): AcademicYear
    {
        $checked = $this->check($data);
        $calendarId = $this->calendarIdOf($tenantId, $yearId);

        try {
            return DB::transaction(function () use ($tenantId, $calendarId, $yearId, $checked): AcademicYear {
                $calendar = $this->calendars->lock($tenantId, $calendarId);

                $year = AcademicYear::query()
                    ->withoutGlobalScope('tenant')
                    ->where('calendar_id', $calendar->id)
                    ->whereKey($yearId)
                    ->lockForUpdate()
                    ->first();

                if (! $year instanceof AcademicYear) {
                    throw CalendarException::yearNotFound();
                }

                $this->assertFitsCalendar($calendar, $checked, $year->id);
                $this->assertHolidaysInside($year, $checked);

                $before = $this->snapshot($year);

                $year->name = $checked['name'];
                $year->start_date = $checked['start'];
                $year->end_date = $checked['end'];
                $year->save();

                $this->syncTerms($year, $checked['terms']);

                $after = $this->snapshot($year->refresh());
                $changes = [];

                foreach ($after as $field => $value) {
                    if ($before[$field] !== $value) {
                        $changes[$field] = [$before[$field], $value];
                    }
                }

                if ($changes !== []) {
                    $this->audit->record(
                        AcademicAuditAction::YEAR_UPDATED,
                        AcademicAuditAction::SUBJECT_YEAR,
                        $year->id,
                        ['kalender' => $calendar->name, ...$changes],
                        tenantId: $tenantId,
                    );
                }

                return $year;
            });
        } catch (UniqueConstraintViolationException) {
            throw CalendarException::duplicateYearName($checked['name']);
        }
    }

    // ── Pemeriksaan ───────────────────────────────────────────────────────────

    /**
     * @return array{name: string, start: CarbonImmutable, end: CarbonImmutable, terms: list<array{name: string, start: CarbonImmutable, end: CarbonImmutable}>}
     */
    private function check(AcademicYearData $data): array
    {
        $name = trim($data->name);

        if (preg_match(self::NAME_PATTERN, $name, $parts) !== 1 || (int) $parts[2] !== (int) $parts[1] + 1) {
            throw CalendarException::invalidYearName();
        }

        $start = $this->date($data->startDate, 'start_date');
        $end = $this->date($data->endDate, 'end_date');

        if ($end->lessThanOrEqualTo($start)) {
            throw CalendarException::invalidYearDates();
        }

        if ($end->greaterThan($start->addMonthsNoOverflow(self::MAX_MONTHS))) {
            throw CalendarException::yearTooLong(self::MAX_MONTHS);
        }

        $count = count($data->terms);

        if ($count < 1 || $count > self::MAX_TERMS) {
            throw CalendarException::invalidTermCount(self::MAX_TERMS);
        }

        $terms = [];
        $previousEnd = null;

        foreach ($data->terms as $index => $term) {
            $termName = (string) preg_replace('/\s+/u', ' ', trim($term['name']));

            if ($termName === '' || mb_strlen($termName) > self::TERM_NAME_MAX_LENGTH) {
                throw CalendarException::invalidTermName($index);
            }

            $termStart = $this->date($term['start_date'], "terms.{$index}.start_date");
            $termEnd = $this->date($term['end_date'], "terms.{$index}.end_date");

            if ($termEnd->lessThan($termStart)) {
                throw CalendarException::invalidTermDates($index);
            }

            if ($termStart->lessThan($start) || $termEnd->greaterThan($end)) {
                throw CalendarException::termOutsideYear($index);
            }

            if ($previousEnd !== null && $termStart->lessThanOrEqualTo($previousEnd)) {
                throw CalendarException::termsOverlap($index);
            }

            $terms[] = ['name' => $termName, 'start' => $termStart, 'end' => $termEnd];
            $previousEnd = $termEnd;
        }

        return ['name' => $name, 'start' => $start, 'end' => $end, 'terms' => $terms];
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (Throwable) {
            $date = null;
        }

        // createFromFormat menerima 2026-02-30 sebagai 2 Maret; tolak dengan membandingkan balik.
        if (! $date instanceof CarbonImmutable || $date->toDateString() !== trim($value)) {
            throw new CalendarException('Tanggal tidak valid.', $field);
        }

        return $date;
    }

    /**
     * Nama belum dipakai dan tanggal tidak bertumpang tindih dengan tahun ajaran lain di kalender ini.
     *
     * @param  array{name: string, start: CarbonImmutable, end: CarbonImmutable}  $checked
     */
    private function assertFitsCalendar(AcademicCalendar $calendar, array $checked, ?string $exceptYearId): void
    {
        $others = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $calendar->id)
            ->when($exceptYearId !== null, fn ($query) => $query->whereKeyNot($exceptYearId))
            ->get();

        foreach ($others as $other) {
            if ($other->name === $checked['name']) {
                throw CalendarException::duplicateYearName($checked['name']);
            }
        }

        foreach ($others as $other) {
            $overlaps = $other->start_date->lessThanOrEqualTo($checked['end'])
                && $other->end_date->greaterThanOrEqualTo($checked['start']);

            if ($overlaps) {
                throw CalendarException::yearOverlaps($other->name);
            }
        }
    }

    /**
     * Hari libur tahun ajaran ini (PRD-002 §18 P4) harus tetap di dalam rentang tanggal yang baru.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $checked
     */
    private function assertHolidaysInside(AcademicYear $year, array $checked): void
    {
        $outside = Holiday::query()
            ->withoutGlobalScope('tenant')
            ->where('academic_year_id', $year->id)
            ->where(fn ($query) => $query
                ->where('start_date', '<', $checked['start']->toDateString())
                ->orWhere('end_date', '>', $checked['end']->toDateString()))
            ->orderBy('start_date')
            ->first(['name']);

        if ($outside instanceof Holiday) {
            throw new CalendarException("Hari libur {$outside->name} berada di luar tanggal tahun ajaran yang baru. Ubah atau hapus hari liburnya dulu.", 'start_date');
        }
    }

    private function calendarIdOf(string $tenantId, string $yearId): string
    {
        $calendarId = Str::isUuid($yearId)
            ? AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($yearId)->value('calendar_id')
            : null;

        if (! is_string($calendarId)) {
            throw CalendarException::yearNotFound();
        }

        return $calendarId;
    }

    /**
     * Simpan semester sesuai urutan: yang ada diperbarui, yang baru ditambah, kelebihannya dihapus.
     * Belum ada data lain yang merujuk semester di AC-1; kelak foreign key akan menolak penghapusan
     * semester yang sudah dipakai (mis. nilai rapor).
     *
     * @param  list<array{name: string, start: CarbonImmutable, end: CarbonImmutable}>  $terms
     */
    private function syncTerms(AcademicYear $year, array $terms): void
    {
        $existing = Term::query()
            ->withoutGlobalScope('tenant')
            ->where('academic_year_id', $year->id)
            ->get()
            ->keyBy('sequence');

        // Hapus kelebihan dulu, lalu geser tanggal: tidak ada constraint antar-baris semester di database.
        Term::query()
            ->withoutGlobalScope('tenant')
            ->where('academic_year_id', $year->id)
            ->where('sequence', '>', count($terms))
            ->delete();

        foreach ($terms as $index => $data) {
            $sequence = $index + 1;
            $term = $existing->get($sequence) ?? new Term;

            $term->tenant_id = $year->tenant_id;
            $term->academic_year_id = $year->id;
            $term->sequence = $sequence;
            $term->name = $data['name'];
            $term->start_date = $data['start'];
            $term->end_date = $data['end'];
            $term->save();
        }
    }

    /**
     * Ringkasan untuk mencatat perubahan di audit log.
     *
     * @return array{name: string, start_date: string, end_date: string, semester: list<string>}
     */
    private function snapshot(AcademicYear $year): array
    {
        $rows = Term::query()
            ->withoutGlobalScope('tenant')
            ->where('academic_year_id', $year->id)
            ->orderBy('sequence')
            ->get();

        $terms = [];

        foreach ($rows as $term) {
            $terms[] = "{$term->name} ({$term->start_date->toDateString()} s.d. {$term->end_date->toDateString()})";
        }

        return [
            'name' => $year->name,
            'start_date' => $year->start_date->toDateString(),
            'end_date' => $year->end_date->toDateString(),
            'semester' => $terms,
        ];
    }
}
