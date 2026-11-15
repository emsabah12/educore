<?php

namespace Modules\Dormitory\Application\Placement;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\ActiveStudents;
use Modules\Core\Application\Organization\OrganizationTree;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Siapa boleh tinggal di asrama (PRD-008 §5.1, §16.2) — lewat kontrak publik Academic & HR (ADR-001).
 *
 * - Santri: pendaftaran terbuka di lembaga yang berada di bawah node pemilik asrama (OD-DM-02).
 * - Pengawas: pegawai berstatus aktif di yayasan (OD-DM-03).
 * - Jenis kelamin dari Person (Core).
 */
final class ResidentEligibility
{
    public function __construct(
        private readonly ActiveStudents $students,
        private readonly ActiveEmployees $employees,
        private readonly OrganizationTree $tree,
    ) {}

    /**
     * Node pemilik asrama beserta turunannya (lembaga tempat santri boleh terdaftar).
     *
     * @return list<string>
     */
    public function ownerNodeIds(string $tenantId, string $ownerId): array
    {
        return array_values($this->tree->descendantIds($tenantId, $ownerId));
    }

    public function isEligible(string $tenantId, string $ownerId, string $membershipId, PlacementCategory $category): bool
    {
        return in_array($membershipId, $this->eligibleAmong($tenantId, $ownerId, [$membershipId], $category), true);
    }

    /**
     * @param  list<string>  $membershipIds
     * @return list<string>
     */
    public function eligibleAmong(string $tenantId, string $ownerId, array $membershipIds, PlacementCategory $category): array
    {
        return $category === PlacementCategory::Resident
            ? $this->students->withOpenEnrollment($tenantId, $membershipIds, $this->ownerNodeIds($tenantId, $ownerId))
            : $this->employees->activeMembershipIds($tenantId, $membershipIds);
    }

    /**
     * Calon penghuni yang cocok dengan pencarian (min. 3 huruf), maks. 10.
     *
     * @return list<array{membership_id: string, name: string, detail: string}>
     */
    public function search(string $tenantId, string $ownerId, PlacementCategory $category, string $query): array
    {
        if (mb_strlen(trim($query)) < 3) {
            return [];
        }

        $candidates = [];

        if ($category === PlacementCategory::Resident) {
            foreach ($this->students->search($tenantId, $this->ownerNodeIds($tenantId, $ownerId), $query, 10) as $student) {
                $candidates[] = [
                    'membership_id' => $student['membership_id'],
                    'name' => $student['name'],
                    'detail' => self::enrollmentText($student['enrollments']),
                ];
            }

            return $candidates;
        }

        foreach ($this->employees->searchInTenant($tenantId, $query, 10) as $employee) {
            $candidates[] = ['membership_id' => $employee['membership_id'], 'name' => $employee['name'], 'detail' => 'Pegawai'];
        }

        return $candidates;
    }

    /**
     * Nama & jenis kelamin dari Person (Core), per membership.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, array{name: string, gender: Gender|null}>
     */
    public function people(string $tenantId, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $people = [];

        // Satu query (bukan satu per orang): daftar penghuni bisa berisi puluhan orang.
        foreach (DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.tenant_id', $tenantId)
            ->whereIn('m.id', $membershipIds)
            ->get(['m.id', 'p.name', 'p.gender']) as $row) {
            $people[(string) $row->id] = [
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : Gender::tryFrom((string) $row->gender),
            ];
        }

        return $people;
    }

    /**
     * Data tampilan santri (lembaga, NIS, kelas) per membership; pengawas tidak termasuk.
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string> membership_id → "MA Unit 1 · NIS 123 · Kelas X-A"
     */
    public function studentDetails(string $tenantId, array $membershipIds): array
    {
        $details = [];

        foreach ($this->students->describe($tenantId, $membershipIds) as $membershipId => $student) {
            $details[$membershipId] = self::enrollmentText($student['enrollments']);
        }

        return $details;
    }

    /**
     * @param  list<array{organization_id: string, organization: string, student_number: string|null, class: string|null}>  $enrollments
     */
    private static function enrollmentText(array $enrollments): string
    {
        if ($enrollments === []) {
            return 'Tidak ada pendaftaran aktif';
        }

        $parts = [];

        foreach ($enrollments as $enrollment) {
            $parts[] = implode(' · ', array_filter([
                $enrollment['organization'],
                $enrollment['student_number'] === null ? null : 'NIS '.$enrollment['student_number'],
                $enrollment['class'] === null ? null : 'Kelas '.$enrollment['class'],
            ]));
        }

        return implode('; ', $parts);
    }
}
