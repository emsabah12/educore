<?php

namespace Modules\Academic\Application\Calendar;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * "Jadikan tahun berjalan" (PRD-002 §4.4): memindahkan tanda berjalan di sebuah kalender.
 * Tidak otomatis berganti menurut tanggal [ASUMSI, disetujui owner 2026-10-17].
 */
final class SetCurrentYear
{
    public function __construct(
        private readonly ManageCalendars $calendars,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws CalendarException
     */
    public function handle(string $tenantId, string $yearId): AcademicYear
    {
        $calendarId = Str::isUuid($yearId)
            ? AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($yearId)->value('calendar_id')
            : null;

        if (! is_string($calendarId)) {
            throw CalendarException::yearNotFound();
        }

        return DB::transaction(function () use ($tenantId, $calendarId, $yearId): AcademicYear {
            // Kunci kalender: dua admin yang menekan tombol bersamaan tidak menghasilkan dua tahun berjalan.
            $calendar = $this->calendars->lock($tenantId, $calendarId);

            $years = AcademicYear::query()
                ->withoutGlobalScope('tenant')
                ->where('calendar_id', $calendar->id)
                ->get();

            $target = $years->firstWhere('id', $yearId);

            if (! $target instanceof AcademicYear) {
                throw CalendarException::yearNotFound();
            }

            if ($target->is_current) {
                return $target;
            }

            $previous = $years->firstWhere('is_current', true);

            // Lepas dulu tanda yang lama: partial unique index menolak dua tahun berjalan sekaligus.
            AcademicYear::query()
                ->withoutGlobalScope('tenant')
                ->where('calendar_id', $calendar->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $target->is_current = true;
            $target->save();

            $this->audit->record(
                AcademicAuditAction::YEAR_SET_CURRENT,
                AcademicAuditAction::SUBJECT_YEAR,
                $target->id,
                [
                    'kalender' => $calendar->name,
                    'tahun_berjalan' => [$previous instanceof AcademicYear ? $previous->name : null, $target->name],
                ],
                tenantId: $tenantId,
            );

            return $target;
        });
    }
}
