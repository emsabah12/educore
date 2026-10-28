<?php

namespace Modules\Academic\Application\Guardian;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Core\Domain\Tenancy\MembershipStatus;

/**
 * Pencarian orang tua/wali yang SUDAH tercatat di yayasan (PRD-002 §4.5, revisi [OWNER, 2026-10-28]),
 * agar data tidak diinput ulang. Dipakai Admin Yayasan & Operator Akademik (dicek pemanggil).
 *
 * - Kata kunci: sebagian nama (min. 3 huruf) atau sebagian nomor HP (min. 4 angka).
 * - Hanya orang yang sudah tertaut sebagai orang tua/wali siswa mana pun (bukan seluruh anggota
 *   yayasan, agar HP pegawai tidak bisa dicari dari sini), keanggotaan aktif.
 * - Hasil: nama, HP tersamar, dan nama anak + lembaga aktifnya (maks. 3, sisanya dihitung) supaya
 *   operator yakin orangnya benar [OWNER, 2026-10-28].
 *
 * @phpstan-type GuardianHit array{membership_id: string, name: string, phone: string|null, children: list<array{name: string, organization: string|null}>, more_children: int, already_linked: bool}
 */
final class GuardianDirectory
{
    public const LIMIT = 10;

    public const MIN_NAME_LENGTH = 3;

    public const MIN_PHONE_DIGITS = 4;

    private const CHILDREN_SHOWN = 3;

    /**
     * Kata kunci yang sah: [jenis, nilai], atau null bila terlalu pendek.
     *
     * @return array{0: 'phone'|'name', 1: string}|null
     */
    public static function parse(mixed $query): ?array
    {
        $text = StudentInput::text($query);

        if ($text === null) {
            return null;
        }

        $phone = (string) StudentInput::phone($text);

        if (preg_match('/^\+?\d+$/', $phone) === 1) {
            $digits = ltrim($phone, '+');

            return strlen($digits) >= self::MIN_PHONE_DIGITS ? ['phone', $digits] : null;
        }

        $name = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($name) >= self::MIN_NAME_LENGTH ? ['name', mb_substr($name, 0, 100)] : null;
    }

    /**
     * @param  array{0: 'phone'|'name', 1: string}  $query  hasil parse()
     * @param  string|null  $studentId  bila diisi, tandai yang sudah tertaut ke siswa ini
     * @return list<GuardianHit>
     */
    public function search(string $tenantId, array $query, ?string $studentId = null): array
    {
        [$kind, $value] = $query;
        $like = '%'.addcslashes($value, '%_\\').'%';

        $rows = DB::table('memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereExists(fn (Builder $links) => $links->select(DB::raw(1))
                ->from('ac_student_guardians as g')
                ->whereColumn('g.guardian_membership_id', 'm.id'))
            ->when(
                $kind === 'phone',
                fn (Builder $q) => $q->whereRaw("ltrim(p.phone, '+') LIKE ?", [$like]),
                fn (Builder $q) => $q->where('p.name', 'ilike', $like),
            )
            ->orderBy('p.name')
            ->orderBy('m.id')
            ->limit(self::LIMIT)
            ->get(['m.id', 'p.name', 'p.phone']);

        $membershipIds = [];

        foreach ($rows as $row) {
            $membershipIds[] = (string) $row->id;
        }

        $children = $this->childrenOf($membershipIds);
        $linked = [];

        if ($studentId !== null && $membershipIds !== []) {
            foreach (DB::table('ac_student_guardians')->where('student_id', $studentId)->pluck('guardian_membership_id') as $id) {
                $linked[(string) $id] = true;
            }
        }

        $hits = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $list = $children[$id] ?? [];

            $hits[] = [
                'membership_id' => $id,
                'name' => (string) $row->name,
                'phone' => $row->phone === null ? null : self::maskPhone((string) $row->phone),
                'children' => array_slice($list, 0, self::CHILDREN_SHOWN),
                'more_children' => max(0, count($list) - self::CHILDREN_SHOWN),
                'already_linked' => isset($linked[$id]),
            ];
        }

        return $hits;
    }

    /**
     * "081311112222" → "0813••••2222".
     */
    public static function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 8) {
            return substr($phone, 0, 2).str_repeat('•', max(0, $length - 4)).substr($phone, -2);
        }

        return substr($phone, 0, 4).str_repeat('•', $length - 8).substr($phone, -4);
    }

    /**
     * Anak tiap orang tua/wali, urut nama; lembaga = pendaftaran aktif pertama (urut nama lembaga).
     *
     * @param  list<string>  $membershipIds
     * @return array<string, list<array{name: string, organization: string|null}>>
     */
    private function childrenOf(array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $rows = DB::table('ac_student_guardians as g')
            ->join('ac_students as s', 's.id', '=', 'g.student_id')
            ->join('memberships as sm', 'sm.id', '=', 's.membership_id')
            ->join('persons as sp', 'sp.id', '=', 'sm.person_id')
            ->whereIn('g.guardian_membership_id', $membershipIds)
            ->orderBy('sp.name')
            ->get([
                'g.guardian_membership_id',
                'sp.name',
                DB::raw('(SELECT o.name FROM ac_enrollments e JOIN organizations o ON o.id = e.organization_id WHERE e.student_id = s.id AND e.end_date IS NULL ORDER BY o.name LIMIT 1) AS organization'),
            ]);

        $children = [];

        foreach ($rows as $row) {
            $children[(string) $row->guardian_membership_id][] = [
                'name' => (string) $row->name,
                'organization' => $row->organization === null ? null : (string) $row->organization,
            ];
        }

        return $children;
    }
}
