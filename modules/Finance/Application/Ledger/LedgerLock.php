<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Kunci periode / tutup buku (PRD-007 §19, PB-4): satu tanggal "terkunci sampai" (akhir bulan) untuk seluruh buku
 * yayasan. Transaksi, batal, dan perubahan yang bertanggal sampai tanggal itu ditolak dengan pesan ramah; database
 * menolaknya juga (trigger `fn_ledger_lock_guard`) sebagai pengaman kedua.
 *
 * - Hanya bulan yang sudah berakhir yang bisa dikunci; kunci boleh dimajukan beberapa bulan sekaligus.
 * - Membuka kunci = memundurkan tanggalnya (atau membuka semua) dengan alasan wajib.
 * - Hak akses (finance.ledger.manage tingkat Yayasan) dicek pemanggil.
 */
final class LedgerLock
{
    /** Penanda pesan dari trigger database. */
    public const DATABASE_MARKER = 'EDUCORE_PERIOD_LOCKED';

    public const REASON_MIN_LENGTH = 5;

    public const REASON_MAX_LENGTH = 200;

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JournalProjector $projector,
    ) {}

    /**
     * Tanggal terakhir yang terkunci (Y-m-d), atau null bila belum ada kunci.
     */
    public function lockedThrough(string $tenantId): ?string
    {
        $value = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->value('locked_through');

        return $value === null ? null : substr((string) $value, 0, 10);
    }

    public function isLocked(string $tenantId, string $date): bool
    {
        $through = $this->lockedThrough($tenantId);

        return $through !== null && substr($date, 0, 10) <= $through;
    }

    /**
     * Tolak transaksi baru bertanggal di periode terkunci.
     *
     * @throws FinanceException
     */
    public function assertOpen(string $tenantId, string $date, string $field = 'form'): void
    {
        $through = $this->lockedThrough($tenantId);

        if ($through !== null && substr($date, 0, 10) <= $through) {
            throw FinanceException::make($field, sprintf(
                'Tanggal %s sudah masuk periode tutup buku (terkunci sampai %s). Pakai tanggal sesudahnya, atau minta pengelola pembukuan Yayasan membuka kuncinya.',
                LedgerDate::long($date),
                LedgerDate::long($through),
            ));
        }
    }

    /**
     * Tolak batal/ubah transaksi lama yang bertanggal di periode terkunci.
     *
     * @throws FinanceException
     */
    public function assertChangeable(string $tenantId, string $date, string $field = 'form'): void
    {
        $through = $this->lockedThrough($tenantId);

        if ($through !== null && substr($date, 0, 10) <= $through) {
            throw FinanceException::make($field, sprintf(
                'Transaksi ini bertanggal %s, di periode yang sudah ditutup buku (terkunci sampai %s). Minta pengelola pembukuan Yayasan membuka kuncinya dulu.',
                LedgerDate::long($date),
                LedgerDate::long($through),
            ));
        }
    }

    /**
     * Tanggal jurnal sebuah tagihan: tanggal 1 bulan periode (bulanan) atau tanggal dibuat (sekali bayar).
     */
    public static function invoiceDate(?string $period, string $createdAt): string
    {
        if ($period !== null && $period !== '') {
            return substr($period, 0, 10);
        }

        return CarbonImmutable::parse($createdAt, 'UTC')->setTimezone(BusinessClock::timezone())->toDateString();
    }

    /**
     * Pesan ramah untuk penolakan dari trigger database (pengaman kedua), atau null bila bukan penolakan kunci.
     */
    public static function messageFor(QueryException $exception): ?string
    {
        if (! str_contains($exception->getMessage(), self::DATABASE_MARKER)) {
            return null;
        }

        $through = preg_match('/'.self::DATABASE_MARKER.' (\d{4}-\d{2}-\d{2})/', $exception->getMessage(), $match) === 1 ? $match[1] : null;

        return $through === null
            ? 'Perubahan ini menyentuh periode yang sudah ditutup buku.'
            : 'Perubahan ini menyentuh periode yang sudah ditutup buku (terkunci sampai '.LedgerDate::long($through).'). Minta pengelola pembukuan Yayasan membuka kuncinya dulu.';
    }

    /**
     * Pilihan "kunci sampai akhir bulan …": bulan sesudah kunci sekarang (atau bulan mulai pembukuan) s.d. bulan lalu.
     *
     * @return list<array{value: string, label: string}>
     */
    public function lockOptions(string $tenantId): array
    {
        $row = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first(['start_date', 'locked_through']);

        if ($row === null || $row->start_date === null) {
            return [];
        }

        $first = $row->locked_through === null
            ? CarbonImmutable::parse(substr((string) $row->start_date, 0, 10))->startOfMonth()
            : CarbonImmutable::parse(substr((string) $row->locked_through, 0, 10))->addDay()->startOfMonth();

        return $this->months($first, $this->lastLockable());
    }

    /**
     * Pilihan "buka kunci sampai …": akhir bulan sebelum kunci sekarang (paling awal bulan mulai pembukuan).
     * Nilai kosong ('') = buka semua.
     *
     * @return list<array{value: string, label: string}>
     */
    public function unlockOptions(string $tenantId): array
    {
        $row = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first(['start_date', 'locked_through']);

        if ($row === null || $row->start_date === null || $row->locked_through === null) {
            return [];
        }

        $first = CarbonImmutable::parse(substr((string) $row->start_date, 0, 10))->startOfMonth();
        $last = CarbonImmutable::parse(substr((string) $row->locked_through, 0, 10))->startOfMonth()->subDay();

        return array_merge(
            [['value' => '', 'label' => 'Buka semua (tidak ada periode terkunci)']],
            array_reverse($this->months($first, $last)),
        );
    }

    /**
     * Kunci sampai akhir bulan `$through` (Y-m-d akhir bulan, atau Y-m). Jurnal antrean disusun dulu supaya tidak ada
     * transaksi yang tertinggal di luar buku.
     *
     * @throws FinanceException
     */
    public function lock(string $tenantId, string $through): void
    {
        $date = $this->monthEnd($through);
        $settings = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first();

        if ($settings === null || $settings->start_date === null) {
            throw FinanceException::make('locked_through', 'Atur tanggal mulai pembukuan dulu.');
        }

        if ($settings->rebuild_version !== null || (int) $settings->rebuilt_version < (int) $settings->config_version) {
            throw FinanceException::make('locked_through', 'Selesaikan "Susun ulang jurnal" dulu sebelum mengunci periode, supaya jurnal yang dikunci sudah memakai pemetaan terbaru.');
        }

        $start = substr((string) $settings->start_date, 0, 10);
        $current = $settings->locked_through === null ? null : substr((string) $settings->locked_through, 0, 10);

        if ($date === null || $date < $start || $date > $this->lastLockable()->toDateString()) {
            throw FinanceException::make('locked_through', 'Pilih bulan yang sudah berakhir, sejak bulan mulai pembukuan.');
        }

        if ($current !== null && $date <= $current) {
            throw FinanceException::make('locked_through', 'Bulan itu sudah terkunci. Pilih bulan sesudah '.$this->monthLabel($current).'.');
        }

        // Susun antrean di luar transaksi kunci (bisa banyak), lalu sekali lagi di dalamnya.
        $this->projector->processQueue($tenantId);

        DB::transaction(function () use ($tenantId, $date, $current): void {
            // FOR UPDATE: transaksi kas yang sedang berjalan selesai dulu (trigger pengaman memegang FOR KEY SHARE).
            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first();

            if ($this->projector->processQueue($tenantId) > 0) {
                throw FinanceException::make('locked_through', 'Masih ada transaksi yang sedang dijurnal. Coba lagi sebentar lagi.');
            }

            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update(['locked_through' => $date, 'updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::PERIOD_LOCKED,
                FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
                $tenantId,
                ['terkunci_sampai' => [$current, $date]],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Buka kunci: mundurkan tanggal kunci ke akhir bulan `$through`, atau null = buka semua. Alasan wajib.
     *
     * @throws FinanceException
     */
    public function unlock(string $tenantId, ?string $through, string $reason): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN_LENGTH || $length > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', sprintf('Tulis alasan membuka kunci (%d–%d karakter).', self::REASON_MIN_LENGTH, self::REASON_MAX_LENGTH));
        }

        $date = $through === null || $through === '' ? null : $this->monthEnd($through);

        if ($through !== null && $through !== '' && $date === null) {
            throw FinanceException::make('unlocked_through', 'Pilih bulan yang tetap terkunci, atau buka semua.');
        }

        DB::transaction(function () use ($tenantId, $date, $reason): void {
            $settings = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $current = $settings?->locked_through === null ? null : substr((string) $settings->locked_through, 0, 10);

            if ($settings === null || $current === null) {
                throw FinanceException::make('unlocked_through', 'Belum ada periode yang terkunci.');
            }

            if ($date !== null && ($date >= $current || $date < substr((string) $settings->start_date, 0, 10))) {
                throw FinanceException::make('unlocked_through', 'Pilih bulan sebelum '.$this->monthLabel($current).', atau buka semua.');
            }

            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update(['locked_through' => $date, 'updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::PERIOD_UNLOCKED,
                FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
                $tenantId,
                ['terkunci_sampai' => [$current, $date], 'alasan' => $reason],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * "Oktober 2026" dari tanggal apa pun di bulan itu.
     */
    public function monthLabel(string $date): string
    {
        $month = CarbonImmutable::parse(substr($date, 0, 10));

        return self::MONTHS[$month->month - 1].' '.$month->year;
    }

    /**
     * Akhir bulan lalu (zona waktu sekolah): bulan berjalan tidak bisa dikunci.
     */
    private function lastLockable(): CarbonImmutable
    {
        return BusinessClock::today()->startOfMonth()->subDay();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function months(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $options = [];

        for ($month = $first->startOfMonth(); $month <= $last; $month = $month->addMonth()) {
            $end = $month->endOfMonth()->toDateString();
            $options[] = ['value' => $end, 'label' => $this->monthLabel($end)];
        }

        return $options;
    }

    /**
     * Akhir bulan dari "Y-m" atau "Y-m-d"; null bila tidak sah.
     */
    private function monthEnd(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', trim($value), $match) !== 1 || (int) $match[2] < 1 || (int) $match[2] > 12) {
            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], 1)?->endOfMonth()->toDateString();
    }
}
