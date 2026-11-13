<?php

namespace Modules\HR\Application\Recruitment;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Recruitment\ApplicantStage;
use Modules\HR\Domain\Recruitment\StepAction;
use Modules\HR\Domain\Recruitment\VacancyStatus;
use stdClass;

/**
 * Tampilan rekrutmen (PRD-001 §20.1, HR-9a): daftar lowongan dengan jumlah pelamar per tahap, dan detail lowongan
 * beserta pelamar & riwayat seleksinya. Cakupan lembaga diberikan pemanggil (`$organizationIds` null = semua).
 *
 * @phpstan-type VacancyRow array{id: string, title: string, organization_id: string, organization: string, position_id: string, position: string, openings: int, closes_on: string|null, requirements: string|null, status: string, status_label: string, counts: array<string, int>, total: int}
 * @phpstan-type StepRow array{action: string, action_label: string, stage_label: string, score: int|null, note: string|null, by: string|null, at: string}
 * @phpstan-type ApplicantRow array{id: string, name: string, gender: string, phone: string|null, email: string|null, education_level: string|null, education_label: string|null, education_major: string|null, note: string|null, stage: string, stage_label: string, file_name: string|null, added_on: string, steps: list<StepRow>}
 */
final class RecruitmentRecords
{
    /**
     * Lowongan Dibuka dulu, lalu Draf, lalu Ditutup; terbaru dulu.
     *
     * @param  list<string>|null  $organizationIds
     * @return list<VacancyRow>
     */
    public function vacancies(string $tenantId, ?array $organizationIds, ?string $status, ?string $organizationId): array
    {
        $query = $this->base($tenantId)
            ->when($organizationIds !== null, fn ($query) => $query->whereIn('v.organization_id', $organizationIds))
            ->when($status !== null, fn ($query) => $query->where('v.status', $status))
            ->when($organizationId !== null, fn ($query) => $query->where('v.organization_id', $organizationId))
            ->orderByRaw("CASE v.status WHEN 'DIBUKA' THEN 0 WHEN 'DRAF' THEN 1 ELSE 2 END")
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->limit(200);

        $rows = $query->get(['v.*', 'o.name as organization', 'j.name as position']);
        $counts = $this->counts(array_values(array_map(fn ($row): string => (string) $row->id, $rows->all())));
        $list = [];

        foreach ($rows as $row) {
            $list[] = $this->vacancyRow($row, $counts[(string) $row->id] ?? []);
        }

        return $list;
    }

    /**
     * @param  list<string>|null  $organizationIds
     * @return VacancyRow|null
     */
    public function vacancy(string $tenantId, string $vacancyId, ?array $organizationIds): ?array
    {
        if (! Str::isUuid($vacancyId)) {
            return null;
        }

        $row = $this->base($tenantId)
            ->where('v.id', $vacancyId)
            ->when($organizationIds !== null, fn ($query) => $query->whereIn('v.organization_id', $organizationIds))
            ->first(['v.*', 'o.name as organization', 'j.name as position']);

        return $row === null ? null : $this->vacancyRow($row, $this->counts([$vacancyId])[$vacancyId] ?? []);
    }

    /**
     * Pelamar urut tahap (berjalan dulu) lalu tanggal ditambahkan.
     *
     * @return list<ApplicantRow>
     */
    public function applicants(string $tenantId, string $vacancyId): array
    {
        $applicants = DB::table('hr_applicants')
            ->where('tenant_id', $tenantId)
            ->where('vacancy_id', $vacancyId)
            ->orderByRaw("CASE stage WHEN 'TES' THEN 0 WHEN 'BERKAS' THEN 1 WHEN 'DITERIMA' THEN 2 ELSE 3 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $steps = [];

        foreach (DB::table('hr_applicant_steps as s')
            ->leftJoin('memberships as m', 'm.id', '=', 's.membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->whereIn('s.applicant_id', $applicants->pluck('id')->map(fn ($id): string => (string) $id)->all())
            // Waktu bisa sama persis (presisi detik); id UUIDv7 naik monoton sehingga menjaga urutan pencatatan.
            ->orderBy('s.created_at')
            ->orderBy('s.id')
            ->get(['s.*', 'p.name as by_name']) as $step) {
            $action = StepAction::from((string) $step->action);
            $steps[(string) $step->applicant_id][] = [
                'action' => $action->value,
                'action_label' => $action->label(),
                'stage_label' => ApplicantStage::from((string) $step->stage)->label(),
                'score' => $step->score === null ? null : (int) $step->score,
                'note' => $step->note === null ? null : (string) $step->note,
                'by' => $step->by_name === null ? null : (string) $step->by_name,
                'at' => self::localDate((string) $step->created_at),
            ];
        }

        $rows = [];

        foreach ($applicants as $applicant) {
            $stage = ApplicantStage::from((string) $applicant->stage);
            $level = $applicant->education_level === null ? null : EducationLevel::tryFrom((string) $applicant->education_level);
            $rows[] = [
                'id' => (string) $applicant->id,
                'name' => (string) $applicant->name,
                'gender' => (string) $applicant->gender,
                'phone' => $applicant->phone === null ? null : (string) $applicant->phone,
                'email' => $applicant->email === null ? null : (string) $applicant->email,
                'education_level' => $level?->value,
                'education_label' => $level?->label(),
                'education_major' => $applicant->education_major === null ? null : (string) $applicant->education_major,
                'note' => $applicant->note === null ? null : (string) $applicant->note,
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'file_name' => $applicant->original_name === null ? null : (string) $applicant->original_name,
                'added_on' => self::localDate((string) $applicant->created_at),
                'steps' => $steps[(string) $applicant->id] ?? [],
            ];
        }

        return $rows;
    }

    private static function localDate(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(BusinessClock::timezone())->toDateString();
    }

    private function base(string $tenantId): Builder
    {
        return DB::table('hr_vacancies as v')
            ->join('organizations as o', 'o.id', '=', 'v.organization_id')
            ->join('hr_positions as j', 'j.id', '=', 'v.position_id')
            ->where('v.tenant_id', $tenantId);
    }

    /**
     * @param  list<string>  $vacancyIds
     * @return array<string, array<string, int>> lowongan → tahap → jumlah
     */
    private function counts(array $vacancyIds): array
    {
        if ($vacancyIds === []) {
            return [];
        }

        $counts = [];

        foreach (DB::table('hr_applicants')
            ->whereIn('vacancy_id', $vacancyIds)
            ->groupBy('vacancy_id', 'stage')
            ->selectRaw('vacancy_id, stage, count(*) AS total')
            ->get() as $row) {
            $counts[(string) $row->vacancy_id][(string) $row->stage] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return VacancyRow
     */
    private function vacancyRow(stdClass $row, array $counts): array
    {
        $status = VacancyStatus::from((string) $row->status);
        $all = [];

        foreach (ApplicantStage::cases() as $stage) {
            $all[$stage->value] = $counts[$stage->value] ?? 0;
        }

        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'organization_id' => (string) $row->organization_id,
            'organization' => (string) $row->organization,
            'position_id' => (string) $row->position_id,
            'position' => (string) $row->position,
            'openings' => (int) $row->openings,
            'closes_on' => $row->closes_on === null ? null : substr((string) $row->closes_on, 0, 10),
            'requirements' => $row->requirements === null ? null : (string) $row->requirements,
            'status' => $status->value,
            'status_label' => $status->label(),
            'counts' => $all,
            'total' => array_sum($all),
        ];
    }
}
