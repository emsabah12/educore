<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Offboarding\ManageOffboarding;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EndReason;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Berhentikan pegawai (PRD-001 §4.4 invarian 1–2): periode berjalan DAN semua penempatan
 * berjalan ditutup di tanggal yang sama, dalam satu transaksi; status menjadi BERHENTI.
 *
 * Person, User (akun login), Membership, dan penugasan Core TIDAK disentuh ([LAMA] BR-011).
 * Pengaturan akses login dilakukan terpisah di halaman kelola akses (OD-HR-03, OD-HR-15).
 *
 * HR-7b (PRD-001 §18.2): checklist offboarding dibuat dari item katalog yang aktif, di transaksi yang sama.
 */
final class EndEmployment
{
    private const NOTE_MAX_LENGTH = 500;

    public function __construct(
        private readonly EmployeeGuard $guard,
        private readonly AuditLogger $audit,
        private readonly ManageOffboarding $offboarding,
    ) {}

    /**
     * @throws EmployeeException
     */
    public function handle(string $tenantId, string $employeeId, EndReason $reason, ?string $note, string $endDate, ?string $membershipId = null): Employee
    {
        if ($reason === EndReason::StatusChanged) {
            throw EmployeeException::rule('end_reason', 'Alasan ini hanya dipakai sistem saat ganti status kepegawaian.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw EmployeeException::rule('end_note', 'Catatan maksimal 500 karakter.');
        }

        $endDate = $this->guard->pastOrToday($endDate, 'end_date', 'Tanggal berhenti');

        return DB::transaction(function () use ($tenantId, $employeeId, $reason, $note, $endDate, $membershipId): Employee {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);

            $period = $this->guard->openPeriod($employee);
            $placements = $this->guard->openPlacements($employee);

            // Tanggal berhenti tidak boleh sebelum awal periode maupun awal penempatan mana pun.
            $latestStart = $period->start_date->toDateString();

            foreach ($placements as $placement) {
                $latestStart = max($latestStart, $placement->start_date->toDateString());
            }

            if ($endDate < $latestStart) {
                throw EmployeeException::rule('end_date', 'Tanggal berhenti tidak boleh sebelum '.EmployeeGuard::formatDate($latestStart).' (awal periode/penempatan terakhir).');
            }

            $closedAt = CarbonImmutable::parse($endDate);

            $period->end_date = $closedAt;
            $period->end_reason = $reason;
            $period->end_note = $note;
            $period->save();

            foreach ($placements as $placement) {
                $placement->end_date = $closedAt;
                $placement->save();
            }

            $employee->status = EmployeeStatus::Ended;
            $employee->save();

            $this->offboarding->start($tenantId, $employee->id, $period->id, $endDate, $membershipId);

            $this->audit->record(
                HrAuditAction::EMPLOYMENT_ENDED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                ['alasan' => $reason->value, 'end_date' => $endDate, 'penempatan_ditutup' => count($placements)],
                tenantId: $tenantId,
            );

            return $employee;
        });
    }
}
