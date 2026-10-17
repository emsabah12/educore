<?php

namespace Modules\Academic\Application\Calendar;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Kalender akademik (PRD-002 §4.4): Kalender Yayasan bawaan, tambah kalender, ubah nama.
 *
 * Kalender tidak dihapus dan tidak dinonaktifkan di AC-1 [ASUMSI, disetujui owner 2026-10-17].
 */
final class ManageCalendars
{
    public const DEFAULT_NAME = 'Kalender Yayasan';

    public const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Kalender default yayasan; dibuat bila belum ada. Aman dipanggil berulang dan bersamaan.
     */
    public function ensureDefault(string $tenantId): AcademicCalendar
    {
        $existing = $this->defaultOf($tenantId);

        if ($existing instanceof AcademicCalendar) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): AcademicCalendar => $this->store($tenantId, self::DEFAULT_NAME, isDefault: true));
        } catch (UniqueConstraintViolationException) {
            // Request lain baru saja membuatnya.
            $created = $this->defaultOf($tenantId);

            if (! $created instanceof AcademicCalendar) {
                throw CalendarException::calendarNotFound();
            }

            return $created;
        }
    }

    /**
     * @throws CalendarException
     */
    public function create(string $tenantId, string $name): AcademicCalendar
    {
        $name = $this->normalizeName($name);

        try {
            return DB::transaction(function () use ($tenantId, $name): AcademicCalendar {
                $this->assertNameFree($tenantId, $name, null);

                return $this->store($tenantId, $name, isDefault: false);
            });
        } catch (UniqueConstraintViolationException) {
            throw CalendarException::duplicateCalendarName($name);
        }
    }

    /**
     * @throws CalendarException
     */
    public function rename(string $tenantId, string $calendarId, string $name): AcademicCalendar
    {
        $name = $this->normalizeName($name);

        try {
            return DB::transaction(function () use ($tenantId, $calendarId, $name): AcademicCalendar {
                $calendar = $this->lock($tenantId, $calendarId);
                $oldName = $calendar->name;

                if ($oldName === $name) {
                    return $calendar;
                }

                $this->assertNameFree($tenantId, $name, $calendar->id);

                $calendar->name = $name;
                $calendar->save();

                $this->audit->record(
                    AcademicAuditAction::CALENDAR_RENAMED,
                    AcademicAuditAction::SUBJECT_CALENDAR,
                    $calendar->id,
                    ['name' => [$oldName, $name]],
                    tenantId: $tenantId,
                );

                return $calendar;
            });
        } catch (UniqueConstraintViolationException) {
            throw CalendarException::duplicateCalendarName($name);
        }
    }

    /**
     * Kunci baris kalender di yayasan ini. Dipakai juga oleh service tahun ajaran agar perubahan
     * tahun ajaran di satu kalender diantrekan (cek tumpang tindih & tahun berjalan tetap benar).
     *
     * @throws CalendarException
     */
    public function lock(string $tenantId, string $calendarId): AcademicCalendar
    {
        if (! Str::isUuid($calendarId)) {
            throw CalendarException::calendarNotFound();
        }

        $calendar = AcademicCalendar::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($calendarId)
            ->lockForUpdate()
            ->first();

        if (! $calendar instanceof AcademicCalendar) {
            throw CalendarException::calendarNotFound();
        }

        return $calendar;
    }

    public function defaultOf(string $tenantId): ?AcademicCalendar
    {
        return AcademicCalendar::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first();
    }

    private function store(string $tenantId, string $name, bool $isDefault): AcademicCalendar
    {
        $calendar = new AcademicCalendar;
        $calendar->tenant_id = $tenantId;
        $calendar->name = $name;
        $calendar->is_default = $isDefault;
        $calendar->save();

        $this->audit->record(
            AcademicAuditAction::CALENDAR_CREATED,
            AcademicAuditAction::SUBJECT_CALENDAR,
            $calendar->id,
            ['name' => $name, 'is_default' => $isDefault],
            tenantId: $tenantId,
        );

        return $calendar;
    }

    private function normalizeName(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw CalendarException::invalidCalendarName();
        }

        return $name;
    }

    private function assertNameFree(string $tenantId, string $name, ?string $exceptId): void
    {
        $taken = AcademicCalendar::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw CalendarException::duplicateCalendarName($name);
        }
    }
}
