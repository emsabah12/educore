<?php

namespace Modules\HR\Application\Offboarding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Checklist offboarding pegawai keluar (PRD-001 §18.2, HR-7b).
 *
 * - Dibuat otomatis saat pegawai diberhentikan (EndEmployment) dari item katalog yang aktif; satu checklist per
 *   periode kepegawaian yang ditutup. Pegawai yang berhenti sebelum HR-7b bisa dibuatkan lewat tombol.
 * - Tiap item BELUM / SELESAI / TIDAK_PERLU + catatan opsional (maks. 300); siapa & kapan dicatat.
 * - Audit tanpa isi catatan.
 *
 * Hak akses (hr.employees.manage tingkat Yayasan) dicek pemanggil.
 */
final class ManageOffboarding
{
    public const STATUS_PENDING = 'BELUM';

    public const STATUS_DONE = 'SELESAI';

    public const STATUS_SKIPPED = 'TIDAK_PERLU';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_SKIPPED];

    /** Kode item yang dicentang otomatis bila akun login pegawai sudah nonaktif / tidak ada. */
    public const ACCOUNT_ITEM = 'AKUN-LOGIN';

    public const NOTE_MAX = 300;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Buat checklist untuk periode yang baru ditutup. Dipanggil di dalam transaksi pemberhentian.
     *
     * @return string|null id checklist, atau null bila katalog tidak punya item aktif / sudah ada
     */
    public function start(string $tenantId, string $employeeId, string $periodId, string $endDate, ?string $membershipId): ?string
    {
        if (DB::table('hr_offboardings')->where('employment_period_id', $periodId)->exists()) {
            return null;
        }

        $items = DB::table('hr_offboarding_items')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($items->isEmpty()) {
            return null;
        }

        $id = (string) Str::uuid7();
        $now = now();

        DB::table('hr_offboardings')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'employment_period_id' => $periodId,
            'end_date' => $endDate,
            'created_by_membership_id' => $membershipId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tasks = [];

        foreach ($items as $index => $item) {
            $tasks[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'offboarding_id' => $id,
                'item_id' => (string) $item->id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'position' => $index + 1,
                'status' => self::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('hr_offboarding_tasks')->insert($tasks);

        $this->audit->record(
            HrAuditAction::OFFBOARDING_STARTED,
            HrAuditAction::SUBJECT_OFFBOARDING,
            $id,
            ['pegawai' => $employeeId, 'tanggal_berhenti' => $endDate, 'jumlah_item' => count($tasks)],
            tenantId: $tenantId,
        );

        return $id;
    }

    /**
     * Buatkan checklist untuk pegawai yang sudah berhenti tetapi belum punya checklist di periode terakhirnya.
     *
     * @throws EmployeeException
     */
    public function startForEnded(string $tenantId, string $employeeId, ?string $membershipId): string
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $membershipId): string {
            $employee = Str::isUuid($employeeId)
                ? DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->lockForUpdate()->first(['id', 'status'])
                : null;

            if ($employee === null) {
                throw EmployeeException::rule('form', 'Pegawai tidak ditemukan.');
            }

            if ((string) $employee->status !== EmployeeStatus::Ended->value) {
                throw EmployeeException::rule('form', 'Checklist offboarding hanya untuk pegawai yang sudah berhenti.');
            }

            $period = DB::table('hr_employment_periods')
                ->where('employee_id', $employeeId)
                ->whereNotNull('end_date')
                ->orderByDesc('end_date')
                ->first(['id', 'end_date']);

            if ($period === null) {
                throw EmployeeException::rule('form', 'Periode kepegawaian terakhir tidak ditemukan.');
            }

            if (DB::table('hr_offboardings')->where('employment_period_id', $period->id)->exists()) {
                throw EmployeeException::rule('form', 'Checklist offboarding pegawai ini sudah ada.');
            }

            return $this->start($tenantId, $employeeId, (string) $period->id, substr((string) $period->end_date, 0, 10), $membershipId)
                ?? throw EmployeeException::rule('form', 'Belum ada item offboarding yang aktif. Tambahkan di Katalog SDM → Item offboarding.');
        });
    }

    /**
     * @throws EmployeeException
     */
    public function updateTask(string $tenantId, string $employeeId, string $taskId, string $status, ?string $note, ?string $membershipId): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw EmployeeException::rule('status', 'Pilih status: belum, selesai, atau tidak perlu.');
        }

        $note = $note === null ? null : trim((string) preg_replace('/\s+/u', ' ', $note));
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw EmployeeException::rule('note', 'Catatan maksimal '.self::NOTE_MAX.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $employeeId, $taskId, $status, $note, $membershipId): void {
            $task = Str::isUuid($taskId)
                ? DB::table('hr_offboarding_tasks as t')
                    ->join('hr_offboardings as o', 'o.id', '=', 't.offboarding_id')
                    ->where('t.tenant_id', $tenantId)
                    ->where('o.employee_id', $employeeId)
                    ->where('t.id', $taskId)
                    ->lockForUpdate()
                    ->first(['t.id', 't.code', 't.status', 't.note'])
                : null;

            if ($task === null) {
                throw EmployeeException::rule('form', 'Item checklist tidak ditemukan.');
            }

            if ((string) $task->status === $status && $task->note === $note) {
                return;
            }

            $done = $status !== self::STATUS_PENDING;

            DB::table('hr_offboarding_tasks')->where('id', $task->id)->update([
                'status' => $status,
                'note' => $note,
                'done_by_membership_id' => $done ? $membershipId : null,
                'done_at' => $done ? now() : null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                HrAuditAction::OFFBOARDING_TASK_UPDATED,
                HrAuditAction::SUBJECT_OFFBOARDING,
                (string) $task->id,
                ['pegawai' => $employeeId, 'item' => (string) $task->code, 'status' => [(string) $task->status, $status]],
                tenantId: $tenantId,
            );
        });
    }
}
