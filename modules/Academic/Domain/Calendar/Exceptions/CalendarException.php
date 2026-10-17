<?php

namespace Modules\Academic\Domain\Calendar\Exceptions;

use DomainException;

/**
 * Pelanggaran aturan kalender akademik (PRD-002 §4.4). Pesannya aman ditampilkan ke pengguna.
 *
 * $field = nama isian form tempat pesan ditampilkan (mis. `name`, `terms.1.start_date`).
 */
final class CalendarException extends DomainException
{
    public function __construct(string $message, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }

    public static function invalidCalendarName(): self
    {
        return new self('Nama kalender wajib diisi, maksimal 100 karakter.', 'name');
    }

    public static function duplicateCalendarName(string $name): self
    {
        return new self("Kalender bernama {$name} sudah ada.", 'name');
    }

    /**
     * Pesan sengaja sama untuk "tidak ada" dan "milik yayasan lain".
     */
    public static function calendarNotFound(): self
    {
        return new self('Kalender tidak ditemukan di yayasan ini.');
    }

    public static function yearNotFound(): self
    {
        return new self('Tahun ajaran tidak ditemukan di yayasan ini.');
    }

    public static function invalidYearName(): self
    {
        return new self('Nama tahun ajaran harus berbentuk TTTT/TTTT dengan tahun kedua = tahun pertama + 1, mis. 2026/2027.', 'name');
    }

    public static function duplicateYearName(string $name): self
    {
        return new self("Tahun ajaran {$name} sudah ada di kalender ini.", 'name');
    }

    public static function invalidYearDates(): self
    {
        return new self('Tanggal selesai harus sesudah tanggal mulai.', 'end_date');
    }

    public static function yearTooLong(int $maxMonths): self
    {
        return new self("Tahun ajaran paling lama {$maxMonths} bulan.", 'end_date');
    }

    public static function yearOverlaps(string $otherName): self
    {
        return new self("Tanggalnya bertumpang tindih dengan tahun ajaran {$otherName} di kalender ini.", 'start_date');
    }

    public static function invalidTermCount(int $max): self
    {
        return new self("Isi 1–{$max} semester.", 'terms');
    }

    public static function invalidTermName(int $index): self
    {
        return new self('Nama semester wajib diisi, maksimal 50 karakter.', "terms.{$index}.name");
    }

    public static function invalidTermDates(int $index): self
    {
        return new self('Tanggal selesai semester tidak boleh sebelum tanggal mulainya.', "terms.{$index}.end_date");
    }

    public static function termOutsideYear(int $index): self
    {
        return new self('Semester harus berada di dalam rentang tanggal tahun ajaran.', "terms.{$index}.start_date");
    }

    public static function termsOverlap(int $index): self
    {
        return new self('Semester harus berurutan dan tidak tumpang tindih dengan semester sebelumnya.', "terms.{$index}.start_date");
    }
}
