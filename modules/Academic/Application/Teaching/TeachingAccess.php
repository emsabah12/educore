<?php

namespace Modules\Academic\Application\Teaching;

use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Support\BusinessClock;

/**
 * Siapa boleh membuka & mengisi Presensi mengajar di sebuah lembaga (PRD-002 §20.2) [ASUMSI]:
 * - Pemilik `academic.teaching.manage` di lembaga itu (lembaga kerja aktif): tanggal mana pun,
 *   tidak di masa depan.
 * - Guru piket lembaga itu hari ini: hanya tanggal hari ini.
 */
final class TeachingAccess
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly DutyRoster $roster,
    ) {}

    public function manages(string $organizationId): bool
    {
        return $this->authorization->allows(AcademicAccess::TEACHING_MANAGE, $organizationId);
    }

    /**
     * Lembaga yang bisa dikelola (izin) atau tempat ia piket hari ini, urut nama.
     *
     * @return list<array{value: string, label: string, manages: bool}>
     */
    public function organizations(string $tenantId, ?string $employeeId): array
    {
        $managed = $this->managedOrganizationIds();
        $duty = $employeeId === null ? [] : $this->roster->dutyOrganizations($tenantId, $employeeId, BusinessClock::todayString());
        $ids = array_values(array_unique([...$managed, ...$duty]));

        if ($ids === []) {
            return [];
        }

        $options = [];

        $organizations = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name, 'manages' => in_array($organization->id, $managed, true)];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function managedOrganizationIds(): array
    {
        return $this->authorization->visibleNodeIds(AcademicAccess::TEACHING_MANAGE);
    }

    /**
     * Boleh mencatat presensi mengajar lembaga ini pada tanggal itu.
     */
    public function canRecord(string $tenantId, string $organizationId, string $date, ?string $employeeId): bool
    {
        if ($date > BusinessClock::todayString()) {
            return false;
        }

        if ($this->manages($organizationId)) {
            return true;
        }

        return $employeeId !== null
            && $date === BusinessClock::todayString()
            && $this->roster->isOnDuty($tenantId, $organizationId, $employeeId, $date);
    }
}
