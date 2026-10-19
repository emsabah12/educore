<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Core\Domain\Person\Gender;

/**
 * Isi halaman detail siswa (PRD-002 §6, AC-1c-a). Pemanggil WAJIB sudah memastikan siswa ini
 * terlihat oleh pengguna (StudentVisibility).
 *
 * - Semua pendaftaran siswa ikut tampil, termasuk di lembaga lain (PRD-002 §5.3), mis. Kepala SMP
 *   melihat bahwa siswanya juga santri MDA.
 * - Data pribadi siswa dan seluruh data orang tua/wali hanya diisi bila $withPersonal (§4.5).
 *
 * @phpstan-type EnrollmentRow array{id: string, organization_id: string, organization: string, organization_code: string, student_number: string|null, entry_type: string, entry_type_code: string, start_date: string, end_date: string|null, end_reason: string|null, end_note: string|null, open: bool}
 * @phpstan-type GuardianRow array{name: string, relation: string, gender: string|null, phone: string|null, is_primary: bool}
 */
final class StudentProfile
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     gender: string|null,
     *     nisn: string|null,
     *     active: bool,
     *     personal: array{birth_place: string|null, birth_date: string|null, phone: string|null, address: string|null}|null,
     *     guardians: list<GuardianRow>|null,
     *     enrollments: array{current: list<EnrollmentRow>, history: list<EnrollmentRow>},
     * }|null null bila siswa tidak ada di yayasan ini
     */
    public function load(string $tenantId, string $studentId, bool $withPersonal): ?array
    {
        $row = DB::table('ac_students as s')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.id', $studentId)
            ->first(['s.id', 's.nisn', 'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'p.phone', 'p.address']);

        if ($row === null) {
            return null;
        }

        $gender = $row->gender === null ? null : Gender::tryFrom((string) $row->gender);
        $enrollments = $this->enrollments($studentId);

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'gender' => $gender?->label(),
            'nisn' => $row->nisn === null ? null : (string) $row->nisn,
            'active' => $enrollments['current'] !== [],
            // Data pribadi anak (kelas CONFIDENTIAL, PRD-002 §4.5): null bila tidak berhak melihat.
            'personal' => $withPersonal ? [
                'birth_place' => $row->birth_place === null ? null : (string) $row->birth_place,
                'birth_date' => $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10),
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'address' => $row->address === null ? null : (string) $row->address,
            ] : null,
            'guardians' => $withPersonal ? $this->guardians($studentId) : null,
            'enrollments' => $enrollments,
        ];
    }

    /**
     * Isi awal form "Ubah data diri" (nilai mentah, bukan label). Pemanggil WAJIB sudah memastikan
     * pengguna boleh mengubah data diri siswa ini (StudentManageAccess::canEditProfile).
     *
     * @return array{name: string, gender: string|null, birth_place: string|null, birth_date: string|null, phone: string|null, address: string|null, nisn: string|null}|null
     */
    public function editValues(string $tenantId, string $studentId): ?array
    {
        $row = DB::table('ac_students as s')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.id', $studentId)
            ->first(['s.nisn', 'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'p.phone', 'p.address']);

        if ($row === null) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'gender' => $row->gender === null ? null : (string) $row->gender,
            'birth_place' => $row->birth_place === null ? null : (string) $row->birth_place,
            'birth_date' => $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10),
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'address' => $row->address === null ? null : (string) $row->address,
            'nisn' => $row->nisn === null ? null : (string) $row->nisn,
        ];
    }

    /**
     * Pendaftaran berjalan (urut nama lembaga) dan riwayat (terbaru dulu).
     *
     * @return array{current: list<EnrollmentRow>, history: list<EnrollmentRow>}
     */
    private function enrollments(string $studentId): array
    {
        $rows = DB::table('ac_enrollments as e')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->where('e.student_id', $studentId)
            ->orderBy('o.name')
            ->orderByDesc('e.start_date')
            ->get([
                'e.id', 'e.organization_id', 'e.student_number', 'e.entry_type', 'e.start_date', 'e.end_date', 'e.end_reason', 'e.end_note',
                'o.name as organization', 'o.code as organization_code',
            ]);

        $current = [];
        $history = [];

        foreach ($rows as $row) {
            $entryType = EntryType::tryFrom((string) $row->entry_type);
            $reason = $row->end_reason === null ? null : EnrollmentEndReason::tryFrom((string) $row->end_reason);
            $endDate = $row->end_date === null ? null : substr((string) $row->end_date, 0, 10);

            $item = [
                'id' => (string) $row->id,
                'organization_id' => (string) $row->organization_id,
                'organization' => (string) $row->organization,
                'organization_code' => (string) $row->organization_code,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'entry_type' => $entryType === null ? (string) $row->entry_type : $entryType->label(),
                'entry_type_code' => (string) $row->entry_type,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => $endDate,
                'end_reason' => $reason?->label(),
                'end_note' => $row->end_note === null ? null : (string) $row->end_note,
                'open' => $endDate === null,
            ];

            if ($endDate === null) {
                $current[] = $item;
            } else {
                $history[] = $item;
            }
        }

        usort($history, fn (array $a, array $b): int => strcmp((string) $b['end_date'], (string) $a['end_date']));

        return ['current' => $current, 'history' => $history];
    }

    /**
     * Orang tua/wali; kontak utama di depan.
     *
     * @return list<GuardianRow>
     */
    private function guardians(string $studentId): array
    {
        $rows = DB::table('ac_student_guardians as g')
            ->join('memberships as m', 'm.id', '=', 'g.guardian_membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('g.student_id', $studentId)
            ->orderByDesc('g.is_primary_contact')
            ->orderBy('p.name')
            ->get(['p.name', 'p.gender', 'p.phone', 'g.relation', 'g.is_primary_contact']);

        $guardians = [];

        foreach ($rows as $row) {
            $relation = GuardianRelation::tryFrom((string) $row->relation);
            $gender = $row->gender === null ? null : Gender::tryFrom((string) $row->gender);

            $guardians[] = [
                'name' => (string) $row->name,
                'relation' => $relation === null ? (string) $row->relation : $relation->label(),
                'gender' => $gender?->label(),
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'is_primary' => (bool) $row->is_primary_contact,
            ];
        }

        return $guardians;
    }
}
