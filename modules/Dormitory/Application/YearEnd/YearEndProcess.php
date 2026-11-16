<?php

namespace Modules\Dormitory\Application\YearEnd;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Mutation\OpenMutations;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Application\Placement\ResidentReview;
use Modules\Dormitory\Contracts\Events\ResidencyEnded;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;
use Modules\Dormitory\Domain\Placement\EndReason;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;
use Modules\Dormitory\Domain\YearEnd\YearEndException;

/**
 * Proses akhir tahun asrama (PRD-008 §9, §19.2): untuk tahun ajaran yang sudah berakhir, kamar berkebijakan
 * Kosongkan mengakhiri penempatan Aktif santrinya (alasan Akhir tahun, tanggal keluar = akhir tahun ajaran);
 * kamar Tetap tidak diubah. Pengawas, rencana, dan santri yang masuk sesudah akhir tahun ajaran tidak tersentuh.
 * Semua atau tidak; sekali per asrama per tahun ajaran.
 *
 * @phpstan-type YearOption array{name: string, end_date: string, processed: bool}
 * @phpstan-type PreviewRoom array{id: string, code: string, building: string, policy: string, policy_label: string, emptied: int, kept: int, review: int}
 * @phpstan-type Preview array{year: YearOption, rooms: list<PreviewRoom>, totals: array{emptied: int, kept: int, review: int}}
 */
final class YearEndProcess
{
    public function __construct(
        private readonly AcademicYears $years,
        private readonly ResidentEligibility $eligibility,
        private readonly ResidentReview $review,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tahun ajaran yang sudah berakhir dari kalender lembaga milik asrama, terbaru dulu. Nama sama di beberapa lembaga
     * digabung (tanggal akhir paling akhir dipakai).
     *
     * @return list<YearOption>
     */
    public function years(string $tenantId, Dormitory $dormitory): array
    {
        $today = BusinessClock::todayString();
        $byName = [];

        foreach ($this->eligibility->ownerNodeIds($tenantId, $dormitory->organization_id) as $organizationId) {
            foreach ($this->years->forOrganization($tenantId, $organizationId) as $year) {
                if ($year['end_date'] > $today) {
                    continue;
                }

                $current = $byName[$year['name']] ?? null;

                if ($current === null || $year['end_date'] > $current) {
                    $byName[$year['name']] = $year['end_date'];
                }
            }
        }

        $processed = array_flip(array_map('strval', DB::table('dm_year_end_runs')->where('dormitory_id', $dormitory->id)->pluck('academic_year_name')->all()));
        $options = [];

        foreach ($byName as $name => $end) {
            $options[] = ['name' => (string) $name, 'end_date' => $end, 'processed' => isset($processed[(string) $name])];
        }

        usort($options, fn (array $a, array $b): int => [$b['end_date'], $b['name']] <=> [$a['end_date'], $a['name']]);

        return $options;
    }

    /**
     * Pratinjau per kamar (tanpa menyimpan).
     *
     * @return Preview
     *
     * @throws YearEndException
     */
    public function preview(string $tenantId, Dormitory $dormitory, string $yearName): array
    {
        $year = $this->year($tenantId, $dormitory, $yearName);
        $plan = $this->plan($tenantId, $dormitory, $year['end_date']);

        return ['year' => $year, 'rooms' => $plan['rooms'], 'totals' => $plan['totals']];
    }

    /**
     * Jalankan (semua atau tidak).
     *
     * @return array{emptied: int, kept: int, review: int}
     *
     * @throws YearEndException
     */
    public function run(string $tenantId, string $dormitoryId, string $yearName, string $actorId): array
    {
        try {
            return DB::transaction(function () use ($tenantId, $dormitoryId, $yearName, $actorId): array {
                $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($dormitoryId)->lockForUpdate()->first();

                if (! $dormitory instanceof Dormitory) {
                    throw YearEndException::make('form', 'Asrama tidak ditemukan.');
                }

                $year = $this->year($tenantId, $dormitory, $yearName);

                if ($year['processed']) {
                    throw YearEndException::make('year', "Asrama ini sudah diproses untuk tahun ajaran {$year['name']}.");
                }

                // Kunci kamar urut id (urutan sama dengan penempatan & mutasi) sebelum mengubah penempatan.
                $roomIds = DB::table('dm_rooms')->where('dormitory_id', $dormitory->id)->orderBy('id')->lockForUpdate()->pluck('id');
                $plan = $this->plan($tenantId, $dormitory, $year['end_date']);

                $members = DB::table('dm_placements')->whereIn('id', $plan['emptyIds'])->pluck('membership_id', 'id');

                foreach ($plan['emptyIds'] as $placementId) {
                    DB::table('dm_placements')->where('id', $placementId)->update([
                        'status' => PlacementStatus::Ended->value,
                        'check_out_on' => $year['end_date'],
                        'end_reason' => EndReason::YearEnd->value,
                        'ended_by' => $actorId,
                        'updated_at' => now(),
                    ]);
                    OpenMutations::lapse($tenantId, $placementId, 'Gugur otomatis: kamar dikosongkan pada proses akhir tahun.');
                    // Finance membatalkan biaya asrama bulan sesudahnya bila santri tidak tinggal lagi (PRD-003 §20).
                    event(new ResidencyEnded($tenantId, (string) $members[$placementId], $year['end_date']));
                }

                $id = (string) Str::uuid7();
                DB::table('dm_year_end_runs')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'organization_id' => $dormitory->organization_id,
                    'dormitory_id' => $dormitory->id,
                    'academic_year_name' => $year['name'],
                    'academic_year_end' => $year['end_date'],
                    'emptied' => $plan['totals']['emptied'],
                    'kept' => $plan['totals']['kept'],
                    'review' => $plan['totals']['review'],
                    'ran_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    DormitoryAuditAction::YEAR_END_RUN,
                    DormitoryAuditAction::SUBJECT_DORMITORY,
                    $dormitory->id,
                    ['asrama' => $dormitory->code, 'tahun_ajaran' => $year['name'], 'kamar' => count($roomIds)] + $plan['totals'],
                    organizationId: $dormitory->organization_id,
                    tenantId: $tenantId,
                );

                return $plan['totals'];
            });
        } catch (UniqueConstraintViolationException) {
            throw YearEndException::make('year', 'Asrama ini sudah diproses untuk tahun ajaran itu.');
        }
    }

    /**
     * Ringkasan proses yang pernah dijalankan (tab Pengaturan asrama).
     *
     * @return list<array{year: string, end_date: string, emptied: int, kept: int, review: int, ran_by: string|null, ran_at: string}>
     */
    public function history(string $dormitoryId): array
    {
        $rows = [];

        foreach (DB::table('dm_year_end_runs as y')
            ->leftJoin('memberships as m', 'm.id', '=', 'y.ran_by')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->where('y.dormitory_id', $dormitoryId)
            ->orderByDesc('y.academic_year_end')
            ->get(['y.*', 'p.name']) as $row) {
            $rows[] = [
                'year' => (string) $row->academic_year_name,
                'end_date' => substr((string) $row->academic_year_end, 0, 10),
                'emptied' => (int) $row->emptied,
                'kept' => (int) $row->kept,
                'review' => (int) $row->review,
                'ran_by' => $row->name === null ? null : (string) $row->name,
                'ran_at' => substr((string) $row->created_at, 0, 10),
            ];
        }

        return $rows;
    }

    /**
     * @return YearOption
     *
     * @throws YearEndException
     */
    private function year(string $tenantId, Dormitory $dormitory, string $yearName): array
    {
        foreach ($this->years($tenantId, $dormitory) as $year) {
            if ($year['name'] === $yearName) {
                return $year;
            }
        }

        throw YearEndException::make('year', 'Pilih tahun ajaran yang sudah berakhir dari daftar.');
    }

    /**
     * @return array{rooms: list<PreviewRoom>, totals: array{emptied: int, kept: int, review: int}, emptyIds: list<string>}
     */
    private function plan(string $tenantId, Dormitory $dormitory, string $yearEnd): array
    {
        $rooms = DB::table('dm_rooms as r')
            ->join('dm_buildings as b', 'b.id', '=', 'r.building_id')
            ->where('r.dormitory_id', $dormitory->id)
            ->orderBy('b.code')
            ->orderBy('r.code')
            ->get(['r.id', 'r.code', 'r.year_end_policy', 'b.name as building']);

        // Santri Aktif yang masuk paling lambat akhir tahun ajaran.
        $active = DB::table('dm_placements')
            ->where('tenant_id', $tenantId)
            ->where('dormitory_id', $dormitory->id)
            ->where('category', PlacementCategory::Resident->value)
            ->where('status', PlacementStatus::Active->value)
            ->where('check_in_on', '<=', CarbonImmutable::parse($yearEnd)->toDateString())
            ->get(['id', 'room_id', 'membership_id', 'organization_id']);

        $byRoom = [];

        foreach ($active as $placement) {
            $byRoom[(string) $placement->room_id][] = ['id' => (string) $placement->id, 'membership_id' => (string) $placement->membership_id, 'organization_id' => (string) $placement->organization_id];
        }

        $result = [];
        $totals = ['emptied' => 0, 'kept' => 0, 'review' => 0];
        $emptyIds = [];

        foreach ($rooms as $room) {
            $policy = YearEndPolicy::from((string) $room->year_end_policy);
            $placements = $byRoom[(string) $room->id] ?? [];
            $empty = $policy === YearEndPolicy::Empty;
            $review = $empty ? 0 : count($this->review->flagged($tenantId, $placements));
            $emptied = $empty ? count($placements) : 0;
            $kept = $empty ? 0 : count($placements);

            if ($empty) {
                array_push($emptyIds, ...array_column($placements, 'id'));
            }

            $result[] = [
                'id' => (string) $room->id,
                'code' => (string) $room->code,
                'building' => (string) $room->building,
                'policy' => $policy->value,
                'policy_label' => $policy->label(),
                'emptied' => $emptied,
                'kept' => $kept,
                'review' => $review,
            ];
            $totals['emptied'] += $emptied;
            $totals['kept'] += $kept;
            $totals['review'] += $review;
        }

        return ['rooms' => $result, 'totals' => $totals, 'emptyIds' => $emptyIds];
    }
}
