<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * Calon "orang yang sama" saat menambah siswa (PRD-002 §4.5, usulan A AC-1b disetujui owner 2026-10-17).
 *
 * Anggota AKTIF yayasan ini yang NAMANYA sama (tanpa beda huruf besar/kecil & spasi ganda), dengan
 * keterangan secukupnya agar operator bisa menilai: peran di Academic dan tanggal lahir. Daftar
 * seluruh anggota tidak pernah ditampilkan; calon hanya muncul bila nama yang diketik sama persis.
 * Hanya yayasan yang sama, agar data yayasan lain tidak bocor.
 */
final class DuplicateCandidates
{
    public const LIMIT = 5;

    /**
     * @return list<array{membership_id: string, label: string}>
     */
    public function find(string $tenantId, string $name): array
    {
        $rows = DB::table('persons as p')
            ->join('memberships as m', 'm.person_id', '=', 'p.id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereRaw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g')) = ?", [StudentInput::comparableName($name)])
            ->orderBy('p.birth_date')
            ->orderBy('m.id')
            ->limit(self::LIMIT)
            ->get(['m.id as membership_id', 'p.name', 'p.birth_date']);

        if ($rows->isEmpty()) {
            return [];
        }

        $membershipIds = [];

        foreach ($rows as $row) {
            $membershipIds[] = (string) $row->membership_id;
        }

        $roles = $this->roles($tenantId, $membershipIds);
        $candidates = [];

        foreach ($rows as $row) {
            $membershipId = (string) $row->membership_id;
            $birthDate = $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10);

            $candidates[] = [
                'membership_id' => $membershipId,
                'label' => (string) $row->name.' — '.($roles[$membershipId] ?? 'anggota yayasan')
                    .', '.($birthDate === null ? 'tanpa tanggal lahir' : 'lahir '.$birthDate),
            ];
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    public function membershipIds(string $tenantId, string $name): array
    {
        return array_column($this->find($tenantId, $name), 'membership_id');
    }

    /**
     * Peran tiap anggota di modul Academic: "siswa SMP Unit 3, MDA Unit 3", "orang tua/wali".
     *
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    private function roles(string $tenantId, array $membershipIds): array
    {
        $enrollments = DB::table('ac_students as s')
            ->leftJoin('ac_enrollments as e', function (JoinClause $join): void {
                $join->on('e.student_id', '=', 's.id')->whereNull('e.end_date');
            })
            ->leftJoin('organizations as o', 'o.id', '=', 'e.organization_id')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.membership_id', $membershipIds)
            ->orderBy('o.name')
            ->get(['s.membership_id', 'o.name as organization']);

        $roles = [];
        $lembaga = [];

        foreach ($enrollments as $row) {
            $membershipId = (string) $row->membership_id;
            $lembaga[$membershipId] ??= [];

            if ($row->organization !== null) {
                $lembaga[$membershipId][] = (string) $row->organization;
            }
        }

        foreach ($lembaga as $membershipId => $names) {
            $roles[$membershipId] = $names === [] ? 'siswa (tidak aktif)' : 'siswa '.implode(', ', $names);
        }

        $guardians = DB::table('ac_student_guardians')
            ->where('tenant_id', $tenantId)
            ->whereIn('guardian_membership_id', $membershipIds)
            ->distinct()
            ->pluck('guardian_membership_id');

        foreach ($guardians as $membershipId) {
            $membershipId = (string) $membershipId;
            $roles[$membershipId] = isset($roles[$membershipId]) ? $roles[$membershipId].'; orang tua/wali' : 'orang tua/wali';
        }

        return $roles;
    }
}
