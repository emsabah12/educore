<?php

namespace Modules\HR\Application\Recruitment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Employee\CreateEmployee;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Recruitment\ApplicantStage;
use Modules\HR\Domain\Recruitment\Exceptions\RecruitmentException;
use stdClass;

/**
 * Jadikan pegawai (PRD-001 §20.2, HR-9b): pelamar **Diterima** dibuat menjadi pegawai lewat alur Tambah pegawai
 * (orang baru; NIK wajib & dicek seperti biasa), lalu ditautkan ke pegawai barunya — sekali saja. Semua dalam satu
 * transaksi: gagal membuat pegawai = pelamar tidak berubah.
 */
final class HireApplicant
{
    public function __construct(
        private readonly CreateEmployee $createEmployee,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pelamar yang boleh dijadikan pegawai (Diterima, belum tertaut), beserta lowongannya; null bila tidak ada.
     */
    public function candidate(string $tenantId, string $applicantId): ?stdClass
    {
        if (! Str::isUuid($applicantId)) {
            return null;
        }

        return DB::table('hr_applicants as a')
            ->join('hr_vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.id', $applicantId)
            ->where('a.stage', ApplicantStage::Accepted->value)
            ->whereNull('a.employee_id')
            ->first(['a.*', 'v.title as vacancy_title', 'v.organization_id', 'v.position_id']);
    }

    /**
     * @throws RecruitmentException
     */
    public function handle(string $applicantId, NewEmployeeData $data): Employee
    {
        return DB::transaction(function () use ($applicantId, $data): Employee {
            $applicant = Str::isUuid($applicantId)
                ? DB::table('hr_applicants')->where('tenant_id', $data->tenantId)->where('id', $applicantId)->lockForUpdate()->first()
                : null;

            if ($applicant === null) {
                throw RecruitmentException::make('form', 'Pelamar tidak ditemukan.');
            }

            if ($applicant->employee_id !== null) {
                throw RecruitmentException::make('form', 'Pelamar ini sudah dijadikan pegawai.');
            }

            if ($applicant->stage !== ApplicantStage::Accepted->value) {
                throw RecruitmentException::make('form', 'Hanya pelamar yang Diterima yang bisa dijadikan pegawai.');
            }

            $employee = $this->createEmployee->handle($data);

            DB::table('hr_applicants')->where('id', $applicantId)->update(['employee_id' => $employee->id, 'updated_at' => now()]);

            $this->audit->record(
                HrAuditAction::APPLICANT_HIRED,
                HrAuditAction::SUBJECT_APPLICANT,
                $applicantId,
                ['lowongan' => (string) $applicant->vacancy_id, 'pegawai' => $employee->id],
                tenantId: $data->tenantId,
            );

            return $employee;
        });
    }
}
