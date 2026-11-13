<?php

namespace Modules\HR\Application\Recruitment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Domain\Recruitment\VacancyStatus;

/**
 * Penghapusan otomatis data pelamar (PRD-001 §20.2, HR-9b; OD-HR-21): pelamar yang **tidak menjadi pegawai** pada
 * lowongan yang sudah **Ditutup lebih dari 1 tahun** dihapus beserta berkas & riwayat tahapnya. Jumlahnya per tahap
 * disimpan di lowongan (`purged_counts`) agar rekap tetap utuh. Dijalankan harian oleh `hr:purge-applicants`.
 */
final class PurgeApplicants
{
    public const RETENTION_YEARS = 1;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentFileStore $files,
    ) {}

    /**
     * @return int jumlah pelamar yang dihapus
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subYears(self::RETENTION_YEARS);
        $total = 0;

        $vacancies = DB::table('hr_vacancies')
            ->where('status', VacancyStatus::Closed->value)
            ->where('closed_at', '<', $cutoff)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('hr_applicants')->whereColumn('hr_applicants.vacancy_id', 'hr_vacancies.id')->whereNull('hr_applicants.employee_id'))
            ->pluck('id');

        foreach ($vacancies as $vacancyId) {
            $total += $this->purgeVacancy((string) $vacancyId);
        }

        return $total;
    }

    private function purgeVacancy(string $vacancyId): int
    {
        $paths = [];

        $count = DB::transaction(function () use ($vacancyId, &$paths): int {
            $vacancy = DB::table('hr_vacancies')->where('id', $vacancyId)->lockForUpdate()->first();

            // Diperiksa ulang: lowongan bisa dibuka lagi sejak daftar diambil.
            if ($vacancy === null || $vacancy->status !== VacancyStatus::Closed->value) {
                return 0;
            }

            $applicants = DB::table('hr_applicants')->where('vacancy_id', $vacancyId)->whereNull('employee_id')->lockForUpdate()->get(['id', 'stage', 'storage_path']);

            if ($applicants->isEmpty()) {
                return 0;
            }

            $counts = $vacancy->purged_counts === null ? [] : (array) json_decode((string) $vacancy->purged_counts, true);

            foreach ($applicants as $applicant) {
                $stage = (string) $applicant->stage;
                $counts[$stage] = (int) ($counts[$stage] ?? 0) + 1;

                if ($applicant->storage_path !== null) {
                    $paths[] = (string) $applicant->storage_path;
                }
            }

            // Riwayat tahap ikut terhapus (cascade).
            DB::table('hr_applicants')->whereIn('id', $applicants->pluck('id')->all())->delete();
            DB::table('hr_vacancies')->where('id', $vacancyId)->update(['purged_counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            $this->audit->record(
                HrAuditAction::APPLICANTS_PURGED,
                HrAuditAction::SUBJECT_VACANCY,
                $vacancyId,
                ['jumlah' => $applicants->count()],
                organizationId: (string) $vacancy->organization_id,
                tenantId: (string) $vacancy->tenant_id,
            );

            return $applicants->count();
        });

        // Berkas dihapus sesudah transaksi berhasil.
        foreach ($paths as $path) {
            $this->files->delete($path);
        }

        return $count;
    }
}
