<?php

namespace Modules\HR\Application\Recruitment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Recruitment\Exceptions\RecruitmentException;
use Modules\HR\Domain\Recruitment\VacancyStatus;
use stdClass;

/**
 * Lowongan (PRD-001 §20.1, HR-9a): dibuat, diubah, dibuka, ditutup, dihapus oleh Admin SDM / Admin Yayasan.
 *
 * - Judul 3–150; lembaga tujuan aktif; jabatan aktif; jumlah dibutuhkan 1–99; batas lamaran opsional; persyaratan
 *   opsional maks. 2.000 karakter.
 * - Draf → Dibuka → Ditutup; yang ditutup boleh dibuka lagi [ASUMSI]. Hanya Draf tanpa pelamar yang bisa dihapus.
 *
 * Hak akses dicek pemanggil (controller).
 */
final class ManageVacancies
{
    public const TITLE_MIN = 3;

    public const TITLE_MAX = 150;

    public const REQUIREMENTS_MAX = 2000;

    public const OPENINGS_MAX = 99;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, organization_id: string, position_id: string, openings: string, closes_on: string, requirements: string}  $input
     * @return string id lowongan
     *
     * @throws RecruitmentException
     */
    public function create(string $tenantId, array $input, string $membershipId): string
    {
        $values = $this->validate($tenantId, $input);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $id, $values, $membershipId): void {
            DB::table('hr_vacancies')->insert($values + [
                'id' => $id,
                'tenant_id' => $tenantId,
                'status' => VacancyStatus::Draft->value,
                'created_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                HrAuditAction::VACANCY_CREATED,
                HrAuditAction::SUBJECT_VACANCY,
                $id,
                ['lembaga' => $values['organization_id'], 'jabatan' => $values['position_id'], 'dibutuhkan' => $values['openings']],
                organizationId: $values['organization_id'],
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * @param  array{title: string, organization_id: string, position_id: string, openings: string, closes_on: string, requirements: string}  $input
     *
     * @throws RecruitmentException
     */
    public function update(string $tenantId, string $vacancyId, array $input): void
    {
        $values = $this->validate($tenantId, $input);

        DB::transaction(function () use ($tenantId, $vacancyId, $values): void {
            $vacancy = $this->find($tenantId, $vacancyId);
            DB::table('hr_vacancies')->where('id', $vacancyId)->update($values + ['updated_at' => now()]);

            $changed = array_keys(array_filter([
                'judul' => $values['title'] !== (string) $vacancy->title,
                'lembaga' => $values['organization_id'] !== (string) $vacancy->organization_id,
                'jabatan' => $values['position_id'] !== (string) $vacancy->position_id,
                'dibutuhkan' => $values['openings'] !== (int) $vacancy->openings,
                'batas_lamaran' => $values['closes_on'] !== ($vacancy->closes_on === null ? null : substr((string) $vacancy->closes_on, 0, 10)),
                'persyaratan' => $values['requirements'] !== ($vacancy->requirements === null ? null : (string) $vacancy->requirements),
            ]));

            if ($changed !== []) {
                $this->audit->record(
                    HrAuditAction::VACANCY_UPDATED,
                    HrAuditAction::SUBJECT_VACANCY,
                    $vacancyId,
                    ['diubah' => $changed],
                    organizationId: $values['organization_id'],
                    tenantId: $tenantId,
                );
            }
        });
    }

    /**
     * @throws RecruitmentException
     */
    public function open(string $tenantId, string $vacancyId): void
    {
        DB::transaction(function () use ($tenantId, $vacancyId): void {
            $vacancy = $this->find($tenantId, $vacancyId);

            if ($vacancy->status === VacancyStatus::Open->value) {
                throw RecruitmentException::make('form', 'Lowongan ini sudah dibuka.');
            }

            DB::table('hr_vacancies')->where('id', $vacancyId)->update([
                'status' => VacancyStatus::Open->value,
                'opened_at' => $vacancy->opened_at ?? now(),
                'closed_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit->record(HrAuditAction::VACANCY_OPENED, HrAuditAction::SUBJECT_VACANCY, $vacancyId, ['dari' => (string) $vacancy->status], organizationId: (string) $vacancy->organization_id, tenantId: $tenantId);
        });
    }

    /**
     * @throws RecruitmentException
     */
    public function close(string $tenantId, string $vacancyId): void
    {
        DB::transaction(function () use ($tenantId, $vacancyId): void {
            $vacancy = $this->find($tenantId, $vacancyId);

            if ($vacancy->status !== VacancyStatus::Open->value) {
                throw RecruitmentException::make('form', 'Hanya lowongan yang sedang dibuka yang bisa ditutup.');
            }

            DB::table('hr_vacancies')->where('id', $vacancyId)->update([
                'status' => VacancyStatus::Closed->value,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(HrAuditAction::VACANCY_CLOSED, HrAuditAction::SUBJECT_VACANCY, $vacancyId, [], organizationId: (string) $vacancy->organization_id, tenantId: $tenantId);
        });
    }

    /**
     * @throws RecruitmentException
     */
    public function delete(string $tenantId, string $vacancyId): void
    {
        DB::transaction(function () use ($tenantId, $vacancyId): void {
            $vacancy = $this->find($tenantId, $vacancyId);

            if ($vacancy->status !== VacancyStatus::Draft->value) {
                throw RecruitmentException::make('form', 'Hanya lowongan berstatus Draf yang bisa dihapus.');
            }

            if (DB::table('hr_applicants')->where('vacancy_id', $vacancyId)->exists()) {
                throw RecruitmentException::make('form', 'Lowongan ini sudah punya pelamar, jadi tidak bisa dihapus.');
            }

            DB::table('hr_vacancies')->where('id', $vacancyId)->delete();
            $this->audit->record(HrAuditAction::VACANCY_DELETED, HrAuditAction::SUBJECT_VACANCY, $vacancyId, [], organizationId: (string) $vacancy->organization_id, tenantId: $tenantId);
        });
    }

    /**
     * @throws RecruitmentException
     */
    private function find(string $tenantId, string $vacancyId): stdClass
    {
        $vacancy = Str::isUuid($vacancyId)
            ? DB::table('hr_vacancies')->where('tenant_id', $tenantId)->where('id', $vacancyId)->lockForUpdate()->first()
            : null;

        return $vacancy ?? throw RecruitmentException::make('form', 'Lowongan tidak ditemukan.');
    }

    /**
     * @param  array{title: string, organization_id: string, position_id: string, openings: string, closes_on: string, requirements: string}  $input
     * @return array{title: string, organization_id: string, position_id: string, openings: int, closes_on: string|null, requirements: string|null}
     *
     * @throws RecruitmentException
     */
    private function validate(string $tenantId, array $input): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $input['title']));

        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw RecruitmentException::make('title', 'Judul lowongan '.self::TITLE_MIN.'–'.self::TITLE_MAX.' karakter, mis. "Guru Matematika MA".');
        }

        $organization = $input['organization_id'];

        if (! Str::isUuid($organization) || DB::table('organizations')->where('tenant_id', $tenantId)->where('id', $organization)->where('status', OrganizationStatus::Active->value)->doesntExist()) {
            throw RecruitmentException::make('organization_id', 'Pilih lembaga tujuan yang aktif.');
        }

        $position = $input['position_id'];

        if (! Str::isUuid($position) || DB::table('hr_positions')->where('tenant_id', $tenantId)->where('id', $position)->where('is_active', true)->doesntExist()) {
            throw RecruitmentException::make('position_id', 'Pilih jabatan yang aktif.');
        }

        $openings = trim($input['openings']);

        if (preg_match('/^\d{1,2}$/', $openings) !== 1 || (int) $openings < 1) {
            throw RecruitmentException::make('openings', 'Jumlah dibutuhkan 1–'.self::OPENINGS_MAX.' orang.');
        }

        $closesOn = trim($input['closes_on']);

        if ($closesOn !== '') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $closesOn) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $closesOn) : null;

            if ($date === null || $date->format('Y-m-d') !== $closesOn) {
                throw RecruitmentException::make('closes_on', 'Isi batas lamaran yang benar.');
            }
        }

        $requirements = trim(str_replace("\r\n", "\n", $input['requirements']));

        if (mb_strlen($requirements) > self::REQUIREMENTS_MAX) {
            throw RecruitmentException::make('requirements', 'Persyaratan paling banyak '.self::REQUIREMENTS_MAX.' karakter.');
        }

        return [
            'title' => $title,
            'organization_id' => $organization,
            'position_id' => $position,
            'openings' => (int) $openings,
            'closes_on' => $closesOn === '' ? null : $closesOn,
            'requirements' => $requirements === '' ? null : $requirements,
        ];
    }
}
