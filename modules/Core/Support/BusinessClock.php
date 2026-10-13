<?php

namespace Modules\Core\Support;

use Carbon\CarbonImmutable;

/**
 * Tanggal "hari ini" menurut zona waktu yayasan (config educore.timezone, default Asia/Jakarta).
 *
 * Aplikasi menyimpan waktu dalam UTC. Untuk aturan berbasis tanggal kalender (mis. "tanggal mulai
 * kerja tidak boleh di masa depan"), pakai kelas ini agar pukul 06.00 WIB tidak dianggap "kemarin".
 */
final class BusinessClock
{
    public static function timezone(): string
    {
        $timezone = config('educore.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Jakarta';
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function todayString(): string
    {
        return self::today()->toDateString();
    }
}
