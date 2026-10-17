<?php

namespace Modules\Academic\Application\Calendar;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Academic\Domain\Calendar\Exceptions\CalendarException;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Settings\ScopedSetting;

/**
 * Menetapkan kalender yang dipakai sebuah jenjang atau lembaga/unit (PRD-002 §4.4).
 *
 * Disimpan sebagai aturan berjenjang `academic.calendar` lewat Core, sehingga hak akses
 * (`core.settings.manage`), kunci dari atas, dan audit nilai lama & baru ikut berlaku.
 *
 * Target:
 *   jenjang terisi, lembaga kosong → semua lembaga berjenjang itu di seluruh yayasan (mis. semua MDA)
 *   lembaga terisi                 → lembaga/unit itu beserta turunannya
 * Tingkat Yayasan tanpa jenjang tidak dipakai: itu sama dengan Kalender Yayasan.
 */
final class AssignCalendar
{
    public function __construct(
        private readonly SetScopedSetting $setScopedSetting,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SettingException
     * @throws CalendarException
     */
    public function handle(?string $organizationId, ?Jenjang $jenjang, string $calendarId, bool $enforced): ScopedSetting
    {
        if ($organizationId === null && $jenjang === null) {
            throw new CalendarException('Pilih jenjang atau lembaga yang akan memakai kalender ini.', 'target');
        }

        return $this->setScopedSetting->handle(CalendarSetting::KEY, $organizationId, $jenjang, $calendarId, $enforced);
    }
}
