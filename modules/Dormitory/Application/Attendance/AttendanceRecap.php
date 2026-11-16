<?php

namespace Modules\Dormitory\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Domain\Attendance\AttendanceKind;
use Modules\Dormitory\Domain\Placement\PlacementCategory;

/**
 * Rekap absen asrama bulanan (PRD-008 §22.3).
 *
 * - Per orang (santri & pengawas) yang punya catatan di bulan itu: jumlah H/I/S/T, sesi tercatat, dan
 *   % hadir = Hadir ÷ sesi tercatat (null bila belum ada sesi; bukan 0). Hari tanpa sesi = belum diisi, bukan
 *   Tidak ada.
 * - Per kamar: sesi yang sudah diisi dibanding sesi yang seharusnya (hari yang sudah lewat di bulan itu × jenis
 *   absen yang dihitung).
 * - Bisa disaring per jenis absen dan per kamar (wali kamar: kamarnya saja). Cakupan dicek pemanggil.
 *
 * @phpstan-type PersonRow array{membership_id: string, name: string, category_label: string, rooms: string, H: int, I: int, S: int, T: int, total: int, percent: float|null}
 * @phpstan-type RoomRow array{id: string, code: string, building: string, filled: int, expected: int}
 */
final class AttendanceRecap
{
    /** Pilihan bulan: bulan berjalan dan 11 bulan sebelumnya. */
    public const MONTHS_BACK = 12;

    /**
     * @param  list<string>|null  $roomIds  null = semua kamar asrama
     * @return array{people: list<PersonRow>, rooms: list<RoomRow>, totals: array{H: int, I: int, S: int, T: int, total: int, percent: float|null}, days: int, kinds: int}
     */
    public function build(string $dormitoryId, ?array $roomIds, string $month, ?string $kindId): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, BusinessClock::timezone()) ?: BusinessClock::today()->startOfMonth();
        $end = $start->endOfMonth();
        $today = BusinessClock::today();
        $lastDay = $end->greaterThan($today) ? $today : $end;
        $days = $lastDay->lessThan($start) ? 0 : (int) round(abs($start->diffInDays($lastDay))) + 1;
        $kinds = $kindId !== null ? 1 : AttendanceKind::query()->withoutGlobalScope('tenant')->where('dormitory_id', $dormitoryId)->where('is_active', true)->count();
        $range = [$start->toDateString(), $end->toDateString()];

        $sessions = DB::table('dm_attendance_sessions as s')
            ->where('s.dormitory_id', $dormitoryId)
            ->whereBetween('s.date', $range)
            ->when($kindId !== null, fn ($query) => $query->where('s.kind_id', $kindId))
            ->when($roomIds !== null, fn ($query) => $query->whereIn('s.room_id', $roomIds));

        // Per orang.
        $people = [];
        $totals = ['H' => 0, 'I' => 0, 'S' => 0, 'T' => 0, 'total' => 0, 'percent' => null];

        foreach ((clone $sessions)
            ->join('dm_attendance_records as ar', 'ar.session_id', '=', 's.id')
            ->join('dm_placements as pl', 'pl.id', '=', 'ar.placement_id')
            ->join('dm_rooms as r', 'r.id', '=', 's.room_id')
            ->join('memberships as m', 'm.id', '=', 'ar.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->groupBy('ar.membership_id', 'p.name')
            ->selectRaw('ar.membership_id, p.name')
            ->selectRaw("string_agg(distinct r.code, ', ') as rooms")
            ->selectRaw('bool_or(pl.category = ?) as supervisor', [PlacementCategory::Supervisor->value])
            ->selectRaw("count(*) filter (where ar.status = 'H') as h, count(*) filter (where ar.status = 'I') as i")
            ->selectRaw("count(*) filter (where ar.status = 'S') as s, count(*) filter (where ar.status = 'T') as t, count(*) as total")
            ->orderBy('p.name')
            ->orderBy('ar.membership_id')
            ->get() as $row) {
            $counts = ['H' => (int) $row->h, 'I' => (int) $row->i, 'S' => (int) $row->s, 'T' => (int) $row->t, 'total' => (int) $row->total];
            $people[] = [
                'membership_id' => (string) $row->membership_id,
                'name' => (string) $row->name,
                'category_label' => ($row->supervisor ? PlacementCategory::Supervisor : PlacementCategory::Resident)->label(),
                'rooms' => (string) $row->rooms,
            ] + $counts + ['percent' => self::percent($counts['H'], $counts['total'])];

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $totals['percent'] = self::percent($totals['H'], $totals['total']);

        // Per kamar: kamar aktif + kamar yang punya sesi di bulan itu.
        $filled = (clone $sessions)->groupBy('s.room_id')->selectRaw('s.room_id, count(*) as total')->pluck('total', 'room_id');
        $rooms = [];

        foreach (DB::table('dm_rooms as r')
            ->join('dm_buildings as g', 'g.id', '=', 'r.building_id')
            ->where('r.dormitory_id', $dormitoryId)
            ->when($roomIds !== null, fn ($query) => $query->whereIn('r.id', $roomIds))
            ->orderBy('g.code')
            ->orderBy('r.code')
            ->get(['r.id', 'r.code', 'r.is_active', 'g.name as building']) as $room) {
            $count = (int) ($filled[$room->id] ?? 0);

            if (! $room->is_active && $count === 0) {
                continue;
            }

            $rooms[] = ['id' => (string) $room->id, 'code' => (string) $room->code, 'building' => (string) $room->building, 'filled' => $count, 'expected' => $room->is_active ? $days * $kinds : $count];
        }

        return ['people' => $people, 'rooms' => $rooms, 'totals' => $totals, 'days' => $days, 'kinds' => $kinds];
    }

    /**
     * Pilihan bulan (terbaru dulu) dan bulan terpilih (bawaan bulan berjalan).
     *
     * @return array{options: list<array{value: string, label: string}>, selected: string}
     */
    public static function months(mixed $requested): array
    {
        $current = BusinessClock::today()->startOfMonth();
        $options = [];

        for ($index = 0; $index < self::MONTHS_BACK; $index++) {
            $month = $current->subMonthsNoOverflow($index);
            $options[] = ['value' => $month->format('Y-m'), 'label' => self::monthLabel($month)];
        }

        $values = array_column($options, 'value');

        return ['options' => $options, 'selected' => is_string($requested) && in_array($requested, $values, true) ? $requested : $values[0]];
    }

    public static function monthLabel(CarbonImmutable $month): string
    {
        $names = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $names[$month->month - 1].' '.$month->year;
    }

    private static function percent(int $present, int $total): ?float
    {
        return $total > 0 ? round($present * 100 / $total, 1) : null;
    }
}
