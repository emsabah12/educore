<?php

namespace Modules\Dormitory\Application\Import;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\ActiveStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\Import\ImportCell;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Placement\ManagePlacements;
use Modules\Dormitory\Application\Placement\PlacementInput;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Placement\Exceptions\PlacementException;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Throwable;

/**
 * Impor penempatan santri (PRD-008 §20). Setiap baris diperiksa dengan aturan yang SAMA dengan pop-up Tempatkan
 * (ManagePlacements::place), berurutan, di dalam satu transaksi database: pratinjau membatalkan transaksi itu,
 * simpan meneruskannya hanya bila semua baris lolos (semua atau tidak, OD-DM-07). Karena itu baris yang bentrok
 * dengan baris sebelumnya di file yang sama (bed sama, santri ganda, kamar penuh) ikut tertangkap, dan hasil
 * pratinjau = hasil simpan.
 *
 * @phpstan-type ResultRow array{line: int, identifier: string, name: string|null, room: string, bed: string|null, locker: string|null, mode: string, date: string|null, error: string|null}
 * @phpstan-type ImportResult array{rows: list<ResultRow>, ready: int, errors: int, rooms: array<string, int>}
 */
final class PlacementImporter
{
    public function __construct(
        private readonly ManagePlacements $placements,
        private readonly ResidentEligibility $eligibility,
        private readonly ActiveStudents $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Periksa seluruh file tanpa menyimpan.
     *
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     * @return ImportResult
     */
    public function preview(string $tenantId, Dormitory $dormitory, array $rows, string $actorId): array
    {
        return $this->process($tenantId, $dormitory, $rows, $actorId, false);
    }

    /**
     * Simpan semua baris; bila ada satu baris salah, tidak ada yang disimpan dan hasilnya dikembalikan untuk ditampilkan.
     *
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     * @return ImportResult
     */
    public function save(string $tenantId, Dormitory $dormitory, array $rows, string $actorId): array
    {
        return $this->process($tenantId, $dormitory, $rows, $actorId, true);
    }

    /**
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     * @return ImportResult
     */
    private function process(string $tenantId, Dormitory $dormitory, array $rows, string $actorId, bool $commit): array
    {
        $rooms = $this->rooms($dormitory->id);
        $identities = $this->identities($tenantId, $dormitory, $rows);
        $result = ['rows' => [], 'ready' => 0, 'errors' => 0, 'rooms' => []];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $line = $this->line($tenantId, $row, $rooms, $identities, $actorId);
                $result['rows'][] = $line;

                if ($line['error'] === null) {
                    $result['ready']++;
                    $result['rooms'][$line['room']] = ($result['rooms'][$line['room']] ?? 0) + 1;
                } else {
                    $result['errors']++;
                }
            }

            if ($commit && $result['errors'] === 0) {
                $this->audit->record(
                    DormitoryAuditAction::PLACEMENTS_IMPORTED,
                    DormitoryAuditAction::SUBJECT_DORMITORY,
                    $dormitory->id,
                    ['asrama' => $dormitory->code, 'baris' => $result['ready']],
                    organizationId: $dormitory->organization_id,
                    tenantId: $tenantId,
                );
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        ksort($result['rooms']);

        return $result;
    }

    /**
     * Satu baris: rapikan isian, kenali santri & kamar, lalu coba tempatkan (savepoint; gagal → dibatalkan).
     *
     * @param  array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}  $row
     * @param  array<string, array{id: string, beds: array<string, string>, lockers: array<string, string>}>  $rooms  "GEDUNG|KAMAR" → kamar
     * @param  array{nisn: array<string, list<string>>, nis: array<string, list<string>>, names: array<string, string>}  $identities
     * @return ResultRow
     */
    private function line(string $tenantId, array $row, array $rooms, array $identities, string $actorId): array
    {
        $value = fn (string $key): ?string => ImportCell::text($row['values'][$key] ?? null);
        $nisn = $value(PlacementImportColumns::NISN);
        $nis = $value(PlacementImportColumns::NIS);
        $building = mb_strtoupper((string) $value(PlacementImportColumns::BUILDING));
        $roomCode = mb_strtoupper((string) $value(PlacementImportColumns::ROOM));
        $bedCode = $value(PlacementImportColumns::BED);
        $lockerCode = $value(PlacementImportColumns::LOCKER);
        $mode = mb_strtoupper((string) $value(PlacementImportColumns::MODE));
        $date = ImportCell::date($row['values'][PlacementImportColumns::DATE] ?? null);
        $note = $value(PlacementImportColumns::NOTE);

        $display = [
            'line' => $row['line'],
            'identifier' => $nisn !== null ? "NISN {$nisn}" : ($nis !== null ? "NIS {$nis}" : '–'),
            'name' => null,
            'room' => trim("{$building} / {$roomCode}", ' /'),
            'bed' => $bedCode === null ? null : mb_strtoupper($bedCode),
            'locker' => $lockerCode === null ? null : mb_strtoupper($lockerCode),
            'mode' => $mode === 'RENCANA' ? 'Rencana' : ($mode === 'LANGSUNG' ? 'Langsung' : $mode),
            'date' => is_string($date) ? $date : null,
            'error' => null,
        ];

        [$membershipId, $error] = $this->resolveStudent($nisn, $nis, $identities);
        $display['name'] = $membershipId === null ? null : ($identities['names'][$membershipId] ?? null);
        $room = $rooms[$building.'|'.$roomCode] ?? null;

        $error ??= match (true) {
            $building === '' || $roomCode === '' => 'Isi kode gedung dan kode kamar.',
            $room === null => "Kamar {$roomCode} di gedung {$building} tidak ada di asrama ini.",
            ! in_array($mode, ['LANGSUNG', 'RENCANA'], true) => 'Jenis harus LANGSUNG atau RENCANA.',
            $date === false => 'Tanggal tidak bisa dibaca. Pakai format YYYY-MM-DD.',
            $mode === 'LANGSUNG' && $date === null => 'Isi tanggal masuk untuk jenis LANGSUNG.',
            $bedCode !== null && ! isset($room['beds'][mb_strtoupper($bedCode)]) => "Bed {$bedCode} tidak ada di kamar {$roomCode}.",
            $lockerCode !== null && ! isset($room['lockers'][mb_strtoupper($lockerCode)]) => "Loker {$lockerCode} tidak ada di kamar {$roomCode}.",
            $note !== null && mb_strlen($note) > ManagePlacements::NOTE_MAX_LENGTH => 'Catatan paling panjang '.ManagePlacements::NOTE_MAX_LENGTH.' karakter.',
            default => null,
        };

        if ($error !== null || $membershipId === null || $room === null) {
            $display['error'] = $error;

            return $display;
        }

        try {
            $placement = $this->placements->place($tenantId, $room['id'], new PlacementInput(
                membershipId: $membershipId,
                category: PlacementCategory::Resident,
                plan: $mode === 'RENCANA',
                bedId: $bedCode === null ? null : $room['beds'][mb_strtoupper($bedCode)],
                lockerId: $lockerCode === null ? null : $room['lockers'][mb_strtoupper($lockerCode)],
                date: is_string($date) ? $date : null,
                note: $note,
            ), $actorId);

            // Bed/loker yang dipilih otomatis ditampilkan di pratinjau.
            $display['bed'] = self::codeOf($placement->bed_id, $room['beds']);
            $display['locker'] = self::codeOf($placement->locker_id, $room['lockers']);
        } catch (PlacementException $exception) {
            $display['error'] = $exception->getMessage();
        }

        return $display;
    }

    /**
     * Kode bed/loker dari id-nya (kode → id).
     *
     * @param  array<string, string>  $codes
     */
    private static function codeOf(?string $id, array $codes): ?string
    {
        $code = $id === null ? false : array_search($id, $codes, true);

        return $code === false ? null : (string) $code;
    }

    /**
     * @param  array{nisn: array<string, list<string>>, nis: array<string, list<string>>, names: array<string, string>}  $identities
     * @return array{0: string|null, 1: string|null} membership_id, pesan salah
     */
    private function resolveStudent(?string $nisn, ?string $nis, array $identities): array
    {
        if ($nisn === null && $nis === null) {
            return [null, 'Isi NISN atau NIS santri.'];
        }

        if ($nisn !== null) {
            $found = $identities['nisn'][$nisn] ?? [];

            return $found === []
                ? [null, "Santri dengan NISN {$nisn} tidak ditemukan di lembaga milik asrama ini (atau pendaftarannya sudah ditutup)."]
                : [$found[0], null];
        }

        $found = $identities['nis'][(string) $nis] ?? [];

        return match (count($found)) {
            0 => [null, "Santri dengan NIS {$nis} tidak ditemukan di lembaga milik asrama ini (atau pendaftarannya sudah ditutup)."],
            1 => [$found[0], null],
            default => [null, "NIS {$nis} cocok dengan lebih dari satu santri. Isi NISN-nya."],
        };
    }

    /**
     * NISN/NIS di file → santri berpendaftaran terbuka di lembaga milik asrama (OD-DM-02), plus namanya.
     *
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     * @return array{nisn: array<string, list<string>>, nis: array<string, list<string>>, names: array<string, string>}
     */
    private function identities(string $tenantId, Dormitory $dormitory, array $rows): array
    {
        $nisns = [];
        $numbers = [];

        foreach ($rows as $row) {
            $nisns[] = (string) ImportCell::text($row['values'][PlacementImportColumns::NISN] ?? null);
            $numbers[] = (string) ImportCell::text($row['values'][PlacementImportColumns::NIS] ?? null);
        }

        $found = $this->students->findByIdentifiers($tenantId, $this->eligibility->ownerNodeIds($tenantId, $dormitory->organization_id), $nisns, $numbers);
        $memberships = [];

        foreach ([$found['nisn'], $found['nis']] as $map) {
            foreach ($map as $ids) {
                array_push($memberships, ...$ids);
            }
        }

        $names = [];

        foreach ($this->eligibility->people($tenantId, array_values(array_unique($memberships))) as $membershipId => $person) {
            $names[$membershipId] = $person['name'];
        }

        return $found + ['names' => $names];
    }

    /**
     * Kamar asrama beserta kode bed/loker → id, dikunci dengan kunci "KODEGEDUNG|KODEKAMAR".
     *
     * @return array<string, array{id: string, beds: array<string, string>, lockers: array<string, string>}>
     */
    private function rooms(string $dormitoryId): array
    {
        $rooms = [];
        $byId = [];

        foreach (DB::table('dm_rooms as r')->join('dm_buildings as b', 'b.id', '=', 'r.building_id')
            ->where('r.dormitory_id', $dormitoryId)->get(['r.id', 'r.code', 'b.code as building']) as $room) {
            $key = (string) $room->building.'|'.(string) $room->code;
            $rooms[$key] = ['id' => (string) $room->id, 'beds' => [], 'lockers' => []];
            $byId[(string) $room->id] = $key;
        }

        foreach (['beds' => 'dm_beds', 'lockers' => 'dm_lockers'] as $kind => $table) {
            foreach (DB::table($table)->whereIn('room_id', array_keys($byId))->get(['id', 'room_id', 'code']) as $resource) {
                $key = $byId[(string) $resource->room_id] ?? null;

                if ($key !== null && isset($rooms[$key])) {
                    $rooms[$key][$kind][(string) $resource->code] = (string) $resource->id;
                }
            }
        }

        return $rooms;
    }
}
