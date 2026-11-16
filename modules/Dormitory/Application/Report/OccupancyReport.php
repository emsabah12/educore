<?php

namespace Modules\Dormitory\Application\Report;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Organization;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Mutation\MutationStatus;
use Modules\Dormitory\Domain\Mutation\MutationType;
use Modules\Dormitory\Domain\Placement\EndReason;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;
use stdClass;

/**
 * Laporan hunian asrama (PRD-008 §12 DM-6, §21).
 *
 * Hunian sekarang per asrama → gedung → kamar. Kapasitas hanya dihitung untuk kamar aktif di gedung & asrama aktif;
 * kamar nonaktif yang masih berpenghuni tetap tampil (kapasitas 0, bertanda Nonaktif) [ASUMSI]. % hunian = Aktif ÷
 * kapasitas efektif; kapasitas 0 → null (tidak dibagi) [ASUMSI]. Rencana dihitung terpisah, tidak ikut persen.
 *
 * Riwayat (pergerakan dalam rentang [dari, sampai]): hunian = penempatan Aktif/Selesai yang masuk ≤ hari itu dan
 * belum keluar sesudah hari itu berakhir (keluar > hari itu). Hunian awal = akhir hari sebelum `dari`, hunian akhir
 * = akhir hari `sampai`, sehingga akhir = awal + masuk − keluar. Mutasi (DM-4) = penempatan lama Selesai (alasan
 * Mutasi) + penempatan baru di tujuan pada tanggal yang sama; dihitung sebagai Mutasi keluar/masuk.
 *
 * Semua query dibatasi daftar asrama yang sudah disaring cakupannya oleh pemanggil.
 *
 * @phpstan-type Metrics array{rooms: int, capacity: int, active: int, residents: int, supervisors: int, planned: int, available: int, percent: float|null, beds_total: int, beds_available: int, lockers_total: int, lockers_available: int, unavailable: int, over_capacity: int}
 * @phpstan-type RoomReport array{id: string, code: string, name: string|null, counted: bool, capacity_basis_label: string, metrics: Metrics}
 * @phpstan-type BuildingReport array{id: string, code: string, name: string, gender_label: string, is_active: bool, metrics: Metrics, rooms: list<RoomReport>}
 * @phpstan-type DormitoryReport array{id: string, code: string, name: string, organization: string, is_active: bool, metrics: Metrics, buildings: list<BuildingReport>}
 * @phpstan-type Movement array{start: int, entries: int, left_dormitory: int, left_school: int, year_end: int, other: int, mutation_in: int, mutation_out: int, emergency: int, end: int}
 * @phpstan-type MovementRow array{id: string, code: string, name: string, movement: Movement}
 * @phpstan-type EventRow array{date: string, name: string, category_label: string, dormitory: string, dormitory_id: string, building: string, room: string, kind: string, kind_label: string, detail: string|null, emergency: bool, cross: bool}
 */
final class OccupancyReport
{
    public const EVENTS_PER_PAGE = 50;

    /** Rentang riwayat paling panjang: 1 tahun. */
    public const MAX_RANGE_DAYS = 366;

    public function __construct(private readonly FacilityDirectory $facilities) {}

    /**
     * @param  list<string>  $dormitoryIds  asrama yang terlihat (dan cocok saringan)
     * @param  string|null  $gender  `L`/`P` gedung, null = semua
     * @return array{dormitories: list<DormitoryReport>, totals: Metrics}
     */
    public function current(array $dormitoryIds, ?string $gender): array
    {
        if ($dormitoryIds === []) {
            return ['dormitories' => [], 'totals' => self::emptyMetrics()];
        }

        $dormitories = Dormitory::query()->withoutGlobalScope('tenant')
            ->whereIn('id', $dormitoryIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $organizations = Organization::query()->withoutGlobalScope('tenant')
            ->whereIn('id', $dormitories->pluck('organization_id')->unique()->values()->all())
            ->pluck('name', 'id');
        $buildings = Building::query()->withoutGlobalScope('tenant')
            ->whereIn('dormitory_id', $dormitoryIds)
            ->when($gender !== null, fn ($query) => $query->where('gender', $gender))
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $buildingIds = $buildings->modelKeys();
        $rooms = $buildingIds === [] ? [] : $this->facilities->roomsMatching(
            fn (Builder $query) => $query->whereIn('r.building_id', $buildingIds),
        );
        $occupancy = $this->activeByCategory(array_column($rooms, 'id'));

        $roomsByBuilding = [];

        foreach ($rooms as $room) {
            $roomsByBuilding[$room['building_id']][] = $room;
        }

        $result = [];
        $overall = self::emptyMetrics();

        foreach ($dormitories as $dormitory) {
            $dormitoryMetrics = self::emptyMetrics();
            $buildingRows = [];

            foreach ($buildings->where('dormitory_id', $dormitory->id) as $building) {
                $buildingMetrics = self::emptyMetrics();
                $roomRows = [];

                foreach ($roomsByBuilding[$building->id] ?? [] as $room) {
                    $counted = $room['is_active'] && $building->is_active && $dormitory->is_active;
                    $active = $occupancy[$room['id']] ?? ['residents' => 0, 'supervisors' => 0];
                    $holders = $room['occupancy']['active'] + $room['occupancy']['planned'];

                    // Kamar nonaktif hanya tampil bila masih ada yang menempati/memesan.
                    if (! $counted && $holders === 0) {
                        continue;
                    }

                    $capacity = $room['capacity'];
                    $metrics = self::finish([
                        'rooms' => 1,
                        'capacity' => $counted ? $capacity['effective'] : 0,
                        'active' => $room['occupancy']['active'],
                        'residents' => $active['residents'],
                        'supervisors' => $active['supervisors'],
                        'planned' => $room['occupancy']['planned'],
                        'available' => $counted ? max(0, $capacity['effective'] - $holders) : 0,
                        'percent' => null,
                        'beds_total' => $capacity['beds_total'],
                        'beds_available' => $capacity['beds_available'],
                        'lockers_total' => $capacity['lockers_total'],
                        'lockers_available' => $capacity['lockers_available'],
                        'unavailable' => ($capacity['beds_total'] - $capacity['beds_available']) + ($capacity['lockers_total'] - $capacity['lockers_available']),
                        'over_capacity' => $room['over_capacity'] ? 1 : 0,
                    ]);

                    $roomRows[] = [
                        'id' => $room['id'],
                        'code' => $room['code'],
                        'name' => $room['name'],
                        'counted' => $counted,
                        'capacity_basis_label' => $room['capacity_basis_label'],
                        'metrics' => $metrics,
                    ];
                    $buildingMetrics = self::add($buildingMetrics, $metrics);
                }

                if ($roomRows === [] && ! ($building->is_active && $dormitory->is_active)) {
                    continue;
                }

                $buildingRows[] = [
                    'id' => $building->id,
                    'code' => $building->code,
                    'name' => $building->name,
                    'gender_label' => FacilityDirectory::genderLabel($building->gender),
                    'is_active' => $building->is_active,
                    'metrics' => self::finish($buildingMetrics),
                    'rooms' => $roomRows,
                ];
                $dormitoryMetrics = self::add($dormitoryMetrics, $buildingMetrics);
            }

            if ($buildingRows === [] && ! $dormitory->is_active) {
                continue;
            }

            $result[] = [
                'id' => $dormitory->id,
                'code' => $dormitory->code,
                'name' => $dormitory->name,
                'organization' => (string) ($organizations[$dormitory->organization_id] ?? ''),
                'is_active' => $dormitory->is_active,
                'metrics' => self::finish($dormitoryMetrics),
                'buildings' => $buildingRows,
            ];
            $overall = self::add($overall, $dormitoryMetrics);
        }

        return ['dormitories' => $result, 'totals' => self::finish($overall)];
    }

    /**
     * Ringkasan pergerakan per asrama dalam rentang.
     *
     * @param  list<string>  $dormitoryIds
     * @return array{dormitories: list<MovementRow>, totals: Movement}
     */
    public function movements(array $dormitoryIds, ?string $gender, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = self::emptyMovement();

        if ($dormitoryIds === []) {
            return ['dormitories' => [], 'totals' => $totals];
        }

        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $per = [];

        // Hunian di akhir hari sebelum `dari` dan di akhir hari `sampai`.
        foreach ($this->placements($dormitoryIds, $gender)
            ->groupBy('pl.dormitory_id')
            ->selectRaw('pl.dormitory_id')
            ->selectRaw('count(*) filter (where pl.check_in_on < ? and (pl.check_out_on is null or pl.check_out_on >= ?)) as start_count', [$fromDay, $fromDay])
            ->selectRaw('count(*) filter (where pl.check_in_on <= ? and (pl.check_out_on is null or pl.check_out_on > ?)) as end_count', [$toDay, $toDay])
            ->get() as $row) {
            $per[(string) $row->dormitory_id] = self::emptyMovement();
            $per[(string) $row->dormitory_id]['start'] = (int) $row->start_count;
            $per[(string) $row->dormitory_id]['end'] = (int) $row->end_count;
        }

        foreach ($this->events($dormitoryIds, $gender, $from, $to) as $event) {
            $key = $event['dormitory_id'];
            $per[$key] ??= self::emptyMovement();

            match ($event['kind']) {
                'MASUK' => $per[$key]['entries']++,
                'MUTASI_MASUK' => $per[$key]['mutation_in']++,
                'MUTASI_KELUAR' => $per[$key]['mutation_out']++,
                EndReason::LeftDormitory->value => $per[$key]['left_dormitory']++,
                EndReason::LeftSchool->value => $per[$key]['left_school']++,
                EndReason::YearEnd->value => $per[$key]['year_end']++,
                default => $per[$key]['other']++,
            };

            // Satu mutasi darurat di dalam asrama yang sama dihitung sekali (pada sisi masuk).
            if ($event['emergency'] && ($event['kind'] === 'MUTASI_MASUK' || $event['cross'])) {
                $per[$key]['emergency']++;
            }
        }

        $rows = [];

        foreach (Dormitory::query()->withoutGlobalScope('tenant')->whereIn('id', $dormitoryIds)->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'is_active']) as $dormitory) {
            $movement = $per[$dormitory->id] ?? null;

            // Asrama nonaktif tanpa hunian dan tanpa pergerakan tidak perlu tampil.
            if ($movement === null && ! $dormitory->is_active) {
                continue;
            }

            $movement ??= self::emptyMovement();
            $rows[] = ['id' => $dormitory->id, 'code' => $dormitory->code, 'name' => $dormitory->name, 'movement' => $movement];

            foreach ($movement as $field => $value) {
                $totals[$field] += $value;
            }
        }

        return ['dormitories' => $rows, 'totals' => $totals];
    }

    /**
     * Daftar kejadian (masuk, keluar, mutasi) dalam rentang, terbaru dulu.
     *
     * @param  list<string>  $dormitoryIds
     * @return list<EventRow>
     */
    public function events(array $dormitoryIds, ?string $gender, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($dormitoryIds === []) {
            return [];
        }

        $range = [$from->toDateString(), $to->toDateString()];
        $executed = MutationStatus::Executed->value;
        $events = [];

        // Masuk: check-in dalam rentang. Penempatan hasil mutasi = Mutasi masuk.
        foreach ($this->placements($dormitoryIds, $gender, withPeople: true)
            ->leftJoin('dm_mutations as mi', function (JoinClause $join) use ($executed): void {
                $join->on('mi.new_placement_id', '=', 'pl.id')->where('mi.status', '=', $executed);
            })
            ->leftJoin('dm_rooms as fr', 'fr.id', '=', 'mi.from_room_id')
            ->leftJoin('dm_dormitories as fd', 'fd.id', '=', 'mi.dormitory_id')
            ->whereBetween('pl.check_in_on', $range)
            ->get(['pl.dormitory_id', 'pl.check_in_on as day', 'pl.category', 'p.name', 'r.code as room_code', 'g.name as building_name', 'd.name as dormitory_name', 'mi.id as mutation_id', 'mi.type as mutation_type', 'mi.dormitory_id as other_dormitory_id', 'fr.code as other_room', 'fd.name as other_dormitory']) as $row) {
            $mutation = $row->mutation_id !== null;
            $events[] = $this->event($row, $mutation ? 'MUTASI_MASUK' : 'MASUK', $mutation ? 'dari' : null);
        }

        // Keluar: check-out dalam rentang (status Selesai). Alasan Mutasi = Mutasi keluar.
        foreach ($this->placements($dormitoryIds, $gender, withPeople: true)
            ->leftJoin('dm_mutations as mo', function (JoinClause $join) use ($executed): void {
                $join->on('mo.placement_id', '=', 'pl.id')->where('mo.status', '=', $executed);
            })
            ->leftJoin('dm_rooms as tr', 'tr.id', '=', 'mo.to_room_id')
            ->leftJoin('dm_dormitories as td', 'td.id', '=', 'mo.to_dormitory_id')
            ->where('pl.status', PlacementStatus::Ended->value)
            ->whereBetween('pl.check_out_on', $range)
            ->get(['pl.dormitory_id', 'pl.check_out_on as day', 'pl.category', 'pl.end_reason', 'p.name', 'r.code as room_code', 'g.name as building_name', 'd.name as dormitory_name', 'mo.id as mutation_id', 'mo.type as mutation_type', 'mo.to_dormitory_id as other_dormitory_id', 'tr.code as other_room', 'td.name as other_dormitory']) as $row) {
            $mutation = $row->end_reason === EndReason::Mutation->value;
            $events[] = $this->event($row, $mutation ? 'MUTASI_KELUAR' : (string) $row->end_reason, $mutation ? 'ke' : null);
        }

        usort($events, fn (array $a, array $b): int => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

        return $events;
    }

    /**
     * Rentang dari alamat. Kosong/tidak sah → awal bulan ini s.d. hari ini; sampai > hari ini → hari ini; lebih dari
     * 1 tahun → `dari` dimajukan.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, adjusted: bool}
     */
    public static function range(mixed $from, mixed $to, CarbonImmutable $today): array
    {
        $adjusted = false;
        $end = self::parseDay($to, $today) ?? $today;

        if ($end->greaterThan($today)) {
            $end = $today;
            $adjusted = true;
        }

        $start = self::parseDay($from, $today) ?? $end->startOfMonth();

        if ($start->greaterThan($end)) {
            $start = $end->startOfMonth();
            $adjusted = true;
        }

        if ((int) round(abs($start->diffInDays($end))) + 1 > self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS - 1);
            $adjusted = true;
        }

        return ['from' => $start, 'to' => $end, 'adjusted' => $adjusted];
    }

    /**
     * @return Movement
     */
    public static function emptyMovement(): array
    {
        return ['start' => 0, 'entries' => 0, 'left_dormitory' => 0, 'left_school' => 0, 'year_end' => 0, 'other' => 0, 'mutation_in' => 0, 'mutation_out' => 0, 'emergency' => 0, 'end' => 0];
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Penempatan yang pernah ditinggali (Aktif/Selesai; rencana & batal tidak dihitung) di asrama terpilih.
     *
     * @param  list<string>  $dormitoryIds
     */
    private function placements(array $dormitoryIds, ?string $gender, bool $withPeople = false): Builder
    {
        $query = DB::table('dm_placements as pl')
            ->join('dm_rooms as r', 'r.id', '=', 'pl.room_id')
            ->join('dm_buildings as g', 'g.id', '=', 'r.building_id')
            ->whereIn('pl.dormitory_id', $dormitoryIds)
            ->whereIn('pl.status', [PlacementStatus::Active->value, PlacementStatus::Ended->value])
            ->when($gender !== null, fn (Builder $query) => $query->where('g.gender', $gender));

        if ($withPeople) {
            $query->join('dm_dormitories as d', 'd.id', '=', 'pl.dormitory_id')
                ->join('memberships as m', 'm.id', '=', 'pl.membership_id')
                ->join('persons as p', 'p.id', '=', 'm.person_id');
        }

        return $query;
    }

    /**
     * @return EventRow
     */
    private function event(stdClass $row, string $kind, ?string $direction): array
    {
        $emergency = ($row->mutation_type ?? null) === MutationType::Emergency->value;
        $cross = ($row->other_dormitory_id ?? null) !== null && (string) $row->other_dormitory_id !== (string) $row->dormitory_id;
        $detail = null;

        if ($direction !== null && ($row->other_room ?? null) !== null) {
            $place = (string) $row->other_room;

            if ($cross) {
                $place .= ' ('.$row->other_dormitory.')';
            }

            $detail = ($emergency ? 'Darurat · ' : '').$direction.' '.$place;
        }

        return [
            'date' => substr((string) $row->day, 0, 10),
            'name' => (string) $row->name,
            'category_label' => PlacementCategory::from((string) $row->category)->label(),
            'dormitory' => (string) $row->dormitory_name,
            'dormitory_id' => (string) $row->dormitory_id,
            'building' => (string) $row->building_name,
            'room' => (string) $row->room_code,
            'kind' => $kind,
            'kind_label' => self::kindLabel($kind),
            'detail' => $detail,
            'emergency' => $emergency,
            'cross' => $cross,
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'MASUK' => 'Masuk',
            'MUTASI_MASUK' => 'Mutasi masuk',
            'MUTASI_KELUAR' => 'Mutasi keluar',
            default => 'Keluar · '.(EndReason::tryFrom($kind)?->label() ?? 'Lainnya'),
        };
    }

    /**
     * Santri & pengawas yang Aktif per kamar.
     *
     * @param  list<string>  $roomIds
     * @return array<string, array{residents: int, supervisors: int}>
     */
    private function activeByCategory(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $result = [];

        foreach (DB::table('dm_placements')
            ->whereIn('room_id', $roomIds)
            ->where('status', PlacementStatus::Active->value)
            ->groupBy('room_id')
            ->selectRaw('room_id')
            ->selectRaw('count(*) filter (where category = ?) as residents', [PlacementCategory::Resident->value])
            ->selectRaw('count(*) filter (where category = ?) as supervisors', [PlacementCategory::Supervisor->value])
            ->get() as $row) {
            $result[(string) $row->room_id] = ['residents' => (int) $row->residents, 'supervisors' => (int) $row->supervisors];
        }

        return $result;
    }

    /**
     * @return Metrics
     */
    private static function emptyMetrics(): array
    {
        return ['rooms' => 0, 'capacity' => 0, 'active' => 0, 'residents' => 0, 'supervisors' => 0, 'planned' => 0, 'available' => 0, 'percent' => null, 'beds_total' => 0, 'beds_available' => 0, 'lockers_total' => 0, 'lockers_available' => 0, 'unavailable' => 0, 'over_capacity' => 0];
    }

    /**
     * @param  Metrics  $total
     * @param  Metrics  $part
     * @return Metrics
     */
    private static function add(array $total, array $part): array
    {
        foreach ($part as $field => $value) {
            if ($field !== 'percent') {
                $total[$field] += (int) $value;
            }
        }

        return $total;
    }

    /**
     * @param  Metrics  $metrics
     * @return Metrics
     */
    private static function finish(array $metrics): array
    {
        $metrics['percent'] = $metrics['capacity'] > 0 ? round($metrics['active'] * 100 / $metrics['capacity'], 1) : null;

        return $metrics;
    }

    private static function parseDay(mixed $value, CarbonImmutable $today): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
