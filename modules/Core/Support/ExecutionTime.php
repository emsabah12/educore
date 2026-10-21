<?php

namespace Modules\Core\Support;

/**
 * Batas waktu proses PHP untuk pekerjaan panjang (mis. simpan impor ratusan baris).
 *
 * Hanya MENAIKKAN batas, tidak pernah menurunkan atau memasang batas baru bila prosesnya tanpa batas
 * (0, mis. CLI & test). set_time_limit() biasa bisa memasang batas 300 detik ke seluruh proses test
 * sehingga test sesudahnya gagal "Maximum execution time exceeded".
 */
final class ExecutionTime
{
    public static function atLeast(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current === 0 || $current >= $seconds) {
            return;
        }

        set_time_limit($seconds);
    }
}
