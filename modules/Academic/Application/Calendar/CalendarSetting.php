<?php

namespace Modules\Academic\Application\Calendar;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Core\Application\Settings\SettingDefinition;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Aturan berjenjang `academic.calendar` (PRD-002 §4.4): kalender yang dipakai sebuah lembaga.
 *
 * Nilainya ID kalender di yayasan aktif. Kosong (default) = Kalender Yayasan. Mekanisme
 * "terdekat menang, kecuali dikunci" milik Core (PRD-000 §8), termasuk audit nilai lama & baru.
 */
final class CalendarSetting implements ValidationRule
{
    public const KEY = 'academic.calendar';

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Kalender akademik',
            default: null,
            rules: ['required', 'string', new self],
        );
    }

    /**
     * Kalender wajib ada di yayasan yang sedang aktif (bukan milik yayasan lain).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // TenantContext berumur satu request (scoped), jadi diambil saat validasi, bukan saat boot.
        $context = app(TenantContext::class)->get();

        $exists = $context !== null
            && is_string($value)
            && Str::isUuid($value)
            && AcademicCalendar::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $context->tenantId)
                ->whereKey($value)
                ->exists();

        if (! $exists) {
            $fail('Kalender tidak ditemukan di yayasan ini.');
        }
    }
}
