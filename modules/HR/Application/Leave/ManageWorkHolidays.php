<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\Holiday;
use Throwable;

/**
 * Hari libur (tanggal merah) kalender kerja HR untuk seluruh yayasan (PRD-001 §13.2): tambah, ubah,
 * hapus. Nama 1–100 karakter; tanggal selesai ≥ tanggal mulai; paling lama 366 hari [ASUMSI];
 * boleh tumpang tindih (dihitung sekali).
 */
final class ManageWorkHolidays
{
    public const NAME_MAX_LENGTH = 100;

    public const MAX_LENGTH_DAYS = 366;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws LeaveException
     */
    public function add(string $tenantId, string $name, string $startDate, string $endDate): Holiday
    {
        [$name, $start, $end] = $this->check($name, $startDate, $endDate);

        return DB::transaction(function () use ($tenantId, $name, $start, $end): Holiday {
            $holiday = new Holiday;
            $holiday->fill(['tenant_id' => $tenantId, 'name' => $name, 'start_date' => $start, 'end_date' => $end]);
            $holiday->save();

            $this->audit->record(HrAuditAction::HOLIDAY_ADDED, HrAuditAction::SUBJECT_HOLIDAY, $holiday->id, $this->snapshot($holiday), tenantId: $tenantId);

            return $holiday;
        });
    }

    /**
     * @return bool true bila berubah
     *
     * @throws LeaveException
     */
    public function update(string $tenantId, string $holidayId, string $name, string $startDate, string $endDate): bool
    {
        [$name, $start, $end] = $this->check($name, $startDate, $endDate);

        return DB::transaction(function () use ($tenantId, $holidayId, $name, $start, $end): bool {
            $holiday = $this->lock($tenantId, $holidayId);
            $before = $this->snapshot($holiday);

            $holiday->name = $name;
            $holiday->start_date = $start;
            $holiday->end_date = $end;
            $after = $this->snapshot($holiday);
            $changes = [];

            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = [$before[$field], $value];
                }
            }

            if ($changes === []) {
                return false;
            }

            $holiday->save();
            $this->audit->record(HrAuditAction::HOLIDAY_UPDATED, HrAuditAction::SUBJECT_HOLIDAY, $holiday->id, $changes, tenantId: $tenantId);

            return true;
        });
    }

    /**
     * @return string nama hari libur yang dihapus
     *
     * @throws LeaveException
     */
    public function remove(string $tenantId, string $holidayId): string
    {
        return DB::transaction(function () use ($tenantId, $holidayId): string {
            $holiday = $this->lock($tenantId, $holidayId);
            $snapshot = $this->snapshot($holiday);
            $holiday->delete();

            $this->audit->record(HrAuditAction::HOLIDAY_REMOVED, HrAuditAction::SUBJECT_HOLIDAY, $holiday->id, $snapshot, tenantId: $tenantId);

            return $holiday->name;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     *
     * @throws LeaveException
     */
    private function check(string $name, string $startDate, string $endDate): array
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw LeaveException::make('name', 'Nama hari libur wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        $start = $this->date($startDate, 'start_date');
        $end = $this->date($endDate, 'end_date');

        if ($end->lessThan($start)) {
            throw LeaveException::make('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        if ($start->diffInDays($end) + 1 > self::MAX_LENGTH_DAYS) {
            throw LeaveException::make('end_date', 'Satu hari libur paling lama '.self::MAX_LENGTH_DAYS.' hari.');
        }

        return [$name, $start, $end];
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== trim($value)) {
            throw LeaveException::make($field, 'Tanggal tidak valid.');
        }

        return $date;
    }

    private function lock(string $tenantId, string $holidayId): Holiday
    {
        $holiday = Str::isUuid($holidayId)
            ? Holiday::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($holidayId)->lockForUpdate()->first()
            : null;

        if (! $holiday instanceof Holiday) {
            throw LeaveException::make('form', 'Hari libur ini sudah tidak ada. Muat ulang halaman.');
        }

        return $holiday;
    }

    /**
     * @return array{nama: string, mulai: string, selesai: string}
     */
    private function snapshot(Holiday $holiday): array
    {
        return ['nama' => $holiday->name, 'mulai' => $holiday->start_date->toDateString(), 'selesai' => $holiday->end_date->toDateString()];
    }
}
