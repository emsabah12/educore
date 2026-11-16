<?php

namespace Modules\Dormitory\Application\Placement;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Facility\RoomCapacity;
use Modules\Dormitory\Application\Mutation\OpenMutations;
use Modules\Dormitory\Contracts\Events\ResidencyEnded;
use Modules\Dormitory\Domain\Facility\Bed;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Locker;
use Modules\Dormitory\Domain\Facility\ResourceKind;
use Modules\Dormitory\Domain\Facility\ResourceStatus;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomComposition;
use Modules\Dormitory\Domain\Facility\RoomResource;
use Modules\Dormitory\Domain\Placement\EndReason;
use Modules\Dormitory\Domain\Placement\Exceptions\PlacementException;
use Modules\Dormitory\Domain\Placement\Placement;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/**
 * Penempatan penghuni (PRD-008 §5, §16 DM-2): tempatkan langsung, rencanakan, check-in, batalkan rencana, check-out,
 * ganti bed/loker di kamar yang sama.
 *
 * Setiap aksi berjalan dalam satu transaksi yang MENGUNCI baris kamar lebih dulu (lalu baris penempatan), sehingga
 * dua petugas yang menyimpan bersamaan tidak bisa melampaui kapasitas atau memakai bed yang sama. Partial unique
 * index di database adalah pengaman terakhir (pesan "coba lagi").
 *
 * Aturan yang diperiksa ulang di setiap langkah (§16.3):
 * 1. asrama, gedung, kamar aktif;
 * 2. jenis kelamin orang = gender gedung (Person tanpa jenis kelamin ditolak);
 * 3. pengawas hanya di kamar Santri + pengawas; santri ≤ slot santri, pengawas ≤ slot pengawas (Direncanakan +
 *    Aktif, tanpa penempatan itu sendiri);
 * 4. bed/loker wajib menurut dasar kapasitas (kosong → disarankan otomatis kode terkecil, OD-DM-05); yang dipilih
 *    harus milik kamar itu, aktif, tersedia, dan belum dipegang penempatan lain;
 * 5. santri: pendaftaran terbuka di lembaga di bawah pemilik asrama; pengawas: pegawai aktif;
 * 6. tidak bertabrakan dengan penempatan Aktif/Direncanakan orang itu (rencana boleh dibuat walau masih Aktif di
 *    kamar lain — keputusan owner 2026-11-15; check-in menunggu check-out).
 *
 * Hak akses (dormitory.residents.manage di node pemilik) dicek pemanggil lewat Gate.
 */
final class ManagePlacements
{
    public const NOTE_MAX_LENGTH = 200;

    public const BULK_MAX = 50;

    /** Tanggal masuk paling jauh ke belakang (hari), sama dengan pembayaran & tabungan. */
    public const BACKDATE_DAYS = 365;

    /** Rencana tanggal masuk paling jauh ke depan (hari). */
    public const PLAN_AHEAD_DAYS = 365;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResidentEligibility $eligibility,
    ) {}

    /**
     * Tempatkan langsung (Aktif) atau Rencanakan.
     *
     * @throws PlacementException
     */
    public function place(string $tenantId, string $roomId, PlacementInput $input, string $actorId): Placement
    {
        $note = self::note($input->note);
        $date = $input->plan
            ? ($input->date === null || $input->date === '' ? null : self::plannedDate($input->date))
            : self::date($input->date, 'date', 'Isi tanggal masuk.');

        return $this->guarded(fn (): Placement => DB::transaction(function () use ($tenantId, $roomId, $input, $actorId, $note, $date): Placement {
            [$room, $building, $dormitory] = $this->lockRoom($tenantId, $roomId);
            $name = $this->assertPersonFits($tenantId, $dormitory, $building, $room, $input->membershipId, $input->category);
            $this->assertNoConflict($tenantId, $input->membershipId, $name, $input->plan, $room->id);

            if (! $input->plan && $date !== null) {
                $this->assertCheckInDate($tenantId, $input->membershipId, $date);
            }

            $occupancy = RoomOccupancy::forRoom($room->id);
            $this->assertCapacity($room, $input->category, $occupancy);
            [$bed, $locker] = $this->resolveResources($room, $input->bedId, $input->lockerId, $occupancy);

            $placement = new Placement;
            $placement->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $room->organization_id,
                'dormitory_id' => $room->dormitory_id,
                'room_id' => $room->id,
                'bed_id' => $bed?->id,
                'locker_id' => $locker?->id,
                'membership_id' => $input->membershipId,
                'category' => $input->category,
                'status' => $input->plan ? PlacementStatus::Planned : PlacementStatus::Active,
                'planned_on' => $input->plan ? $date : null,
                'check_in_on' => $input->plan ? null : $date,
                'note' => $note,
                'created_by' => $actorId,
                'checked_in_by' => $input->plan ? null : $actorId,
            ]);
            $placement->save();

            $this->record(
                $input->plan ? DormitoryAuditAction::PLACEMENT_PLANNED : DormitoryAuditAction::PLACEMENT_CHECKED_IN,
                $placement,
                $room,
                $bed,
                $locker,
                ['tanggal' => $date?->toDateString(), 'langsung' => ! $input->plan],
            );

            return $placement;
        }));
    }

    /**
     * Check-in sebuah rencana. Semua aturan diulang; bed/loker pesanan dipakai bila masih bisa, atau diganti.
     *
     * @throws PlacementException
     */
    public function checkIn(string $tenantId, string $placementId, ?string $date, ?string $bedId, ?string $lockerId, string $actorId): Placement
    {
        $checkInOn = self::date($date, 'date', 'Isi tanggal masuk.');

        return $this->guarded(fn (): Placement => DB::transaction(function () use ($tenantId, $placementId, $checkInOn, $bedId, $lockerId, $actorId): Placement {
            [$placement, $room, $building, $dormitory] = $this->lockPlacement($tenantId, $placementId);

            if ($placement->status !== PlacementStatus::Planned) {
                throw PlacementException::make('form', 'Hanya rencana penempatan yang bisa di-check-in.');
            }

            $name = $this->assertPersonFits($tenantId, $dormitory, $building, $room, $placement->membership_id, $placement->category);
            $current = $this->holdingOf($tenantId, $placement->membership_id, PlacementStatus::Active, null);

            if ($current !== null) {
                throw PlacementException::make('form', "{$name} masih tinggal di {$current}. Check-out dulu dari kamar itu (pindah langsung = mutasi kamar).");
            }

            $this->assertCheckInDate($tenantId, $placement->membership_id, $checkInOn);
            $occupancy = RoomOccupancy::forRoom($room->id, $placement->id);
            $this->assertCapacity($room, $placement->category, $occupancy);
            [$bed, $locker] = $this->resolveResources($room, $bedId, $lockerId, $occupancy);

            $placement->fill([
                'status' => PlacementStatus::Active,
                'check_in_on' => $checkInOn,
                'bed_id' => $bed?->id,
                'locker_id' => $locker?->id,
                'checked_in_by' => $actorId,
            ]);
            $placement->save();

            $this->record(DormitoryAuditAction::PLACEMENT_CHECKED_IN, $placement, $room, $bed, $locker, ['tanggal' => $checkInOn->toDateString(), 'langsung' => false]);

            return $placement;
        }));
    }

    /**
     * Batalkan rencana (bed/loker pesanan lepas). Penempatan Aktif tidak bisa dibatalkan (§5.2).
     *
     * @throws PlacementException
     */
    public function cancel(string $tenantId, string $placementId, string $reason, string $actorId): Placement
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '') {
            throw PlacementException::make('reason', 'Tuliskan alasan pembatalan.');
        }

        if (mb_strlen($reason) > self::NOTE_MAX_LENGTH) {
            throw PlacementException::make('reason', 'Alasan paling panjang '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $placementId, $reason, $actorId): Placement {
            [$placement, $room] = $this->lockPlacement($tenantId, $placementId);

            if ($placement->status !== PlacementStatus::Planned) {
                throw PlacementException::make('form', 'Hanya rencana penempatan yang bisa dibatalkan. Penghuni yang sudah tinggal di-check-out.');
            }

            $placement->fill(['status' => PlacementStatus::Cancelled, 'cancel_reason' => $reason, 'ended_by' => $actorId]);
            $placement->save();

            $this->record(DormitoryAuditAction::PLACEMENT_CANCELLED, $placement, $room, null, null, []);

            return $placement;
        });
    }

    /**
     * Check-out penghuni Aktif. Bed/loker langsung lepas; riwayat tetap.
     *
     * @throws PlacementException
     */
    public function checkOut(string $tenantId, string $placementId, ?string $date, EndReason $reason, ?string $note, string $actorId): Placement
    {
        $checkOutOn = self::date($date, 'date', 'Isi tanggal keluar.');
        $note = self::note($note);

        if (! $reason->chosenByStaff()) {
            throw PlacementException::make('end_reason', 'Pilih alasan keluar.');
        }

        if ($reason === EndReason::Other && $note === null) {
            throw PlacementException::make('note', 'Tuliskan keterangan untuk alasan Lainnya.');
        }

        return DB::transaction(function () use ($tenantId, $placementId, $checkOutOn, $reason, $note, $actorId): Placement {
            [$placement, $room] = $this->lockPlacement($tenantId, $placementId);

            if ($placement->status !== PlacementStatus::Active) {
                throw PlacementException::make('form', 'Hanya penghuni yang sedang tinggal yang bisa di-check-out.');
            }

            if ($checkOutOn->gt(self::today())) {
                throw PlacementException::make('date', 'Tanggal keluar tidak boleh di masa depan.');
            }

            if ($placement->check_in_on !== null && $checkOutOn->lt($placement->check_in_on)) {
                throw PlacementException::make('date', 'Tanggal keluar tidak boleh sebelum tanggal masuk ('.self::display($placement->check_in_on).').');
            }

            $placement->fill([
                'status' => PlacementStatus::Ended,
                'check_out_on' => $checkOutOn,
                'end_reason' => $reason,
                'note' => $note ?? $placement->note,
                'ended_by' => $actorId,
            ]);
            $placement->save();

            $this->record(DormitoryAuditAction::PLACEMENT_CHECKED_OUT, $placement, $room, null, null, ['tanggal' => $checkOutOn->toDateString(), 'alasan' => $reason->value]);
            OpenMutations::lapse($tenantId, $placement->id, 'Gugur otomatis: santri check-out.');

            if ($placement->category === PlacementCategory::Resident) {
                // Modul lain (Finance: biaya asrama, PRD-003 §20) ikut menyesuaikan di transaksi yang sama.
                event(new ResidencyEnded($tenantId, $placement->membership_id, $checkOutOn->toDateString()));
            }

            return $placement;
        });
    }

    /**
     * Tempatkan banyak santri sekaligus ke satu kamar (PRD-008 §19.3): semua atau tidak. Bed/loker dipilih otomatis
     * berurutan. Setiap santri melewati aturan yang sama dengan place(); bila satu ditolak, tidak ada yang disimpan
     * dan pesannya menyebut santri itu.
     *
     * @param  list<string>  $membershipIds
     * @return list<Placement>
     *
     * @throws PlacementException
     */
    public function placeMany(string $tenantId, string $roomId, array $membershipIds, bool $plan, ?string $date, ?string $note, string $actorId): array
    {
        $membershipIds = array_values(array_unique($membershipIds));

        if ($membershipIds === []) {
            throw PlacementException::make('membership_ids', 'Pilih minimal satu santri.');
        }

        if (count($membershipIds) > self::BULK_MAX) {
            throw PlacementException::make('membership_ids', 'Paling banyak '.self::BULK_MAX.' santri sekaligus.');
        }

        $names = $this->eligibility->people($tenantId, $membershipIds);

        return DB::transaction(function () use ($tenantId, $roomId, $membershipIds, $plan, $date, $note, $actorId, $names): array {
            $placed = [];

            foreach ($membershipIds as $membershipId) {
                try {
                    $placed[] = $this->place($tenantId, $roomId, new PlacementInput(
                        membershipId: $membershipId,
                        category: PlacementCategory::Resident,
                        plan: $plan,
                        bedId: null,
                        lockerId: null,
                        date: $date,
                        note: $note,
                    ), $actorId);
                } catch (PlacementException $exception) {
                    $name = $names[$membershipId]['name'] ?? 'Salah satu santri';
                    $message = str_contains($exception->getMessage(), $name) ? $exception->getMessage() : "{$name}: {$exception->getMessage()}";
                    $field = in_array($exception->field, ['date', 'note'], true) ? $exception->field : 'membership_ids';

                    throw PlacementException::make($field, $message);
                }
            }

            return $placed;
        });
    }

    /**
     * Pindahkan santri yang sedang tinggal ke kamar lain (mutasi, PRD-008 §18.2 butir 1), dalam satu transaksi:
     * penempatan lama Selesai (alasan Mutasi, hari ini), penempatan baru Aktif di tujuan (masuk hari ini). Kedua
     * kamar dikunci urut id agar dua mutasi berlawanan arah tidak saling tunggu. Semua aturan §5.3 diulang di tujuan.
     *
     * $dryRun = true hanya memeriksa tanpa menyimpan (dipakai saat mutasi diajukan).
     *
     * @return Placement|null penempatan baru; null bila $dryRun
     *
     * @throws PlacementException
     */
    public function move(string $tenantId, string $placementId, string $toRoomId, ?string $bedId, ?string $lockerId, string $actorId, bool $dryRun = false): ?Placement
    {
        return $this->guarded(fn (): ?Placement => DB::transaction(function () use ($tenantId, $placementId, $toRoomId, $bedId, $lockerId, $actorId, $dryRun): ?Placement {
            $fromRoomId = Placement::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($placementId)->value('room_id');

            if (! is_string($fromRoomId)) {
                throw PlacementException::make('form', 'Penempatan tidak ditemukan.');
            }

            if ($fromRoomId === $toRoomId) {
                throw PlacementException::make('to_room_id', 'Kamar tujuan sama dengan kamar sekarang.');
            }

            $roomIds = [$fromRoomId, $toRoomId];
            sort($roomIds);

            foreach ($roomIds as $roomId) {
                if (! Room::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($roomId)->lockForUpdate()->exists()) {
                    throw PlacementException::make('to_room_id', 'Kamar tujuan tidak ditemukan.');
                }
            }

            $placement = Placement::query()->withoutGlobalScope('tenant')->whereKey($placementId)->lockForUpdate()->firstOrFail();

            if ($placement->status !== PlacementStatus::Active || $placement->category !== PlacementCategory::Resident) {
                throw PlacementException::make('form', 'Hanya santri yang sedang tinggal yang bisa dimutasi.');
            }

            [$room, $building, $dormitory] = $this->lockRoom($tenantId, $toRoomId);
            $this->assertPersonFits($tenantId, $dormitory, $building, $room, $placement->membership_id, PlacementCategory::Resident);
            $occupancy = RoomOccupancy::forRoom($room->id);
            $this->assertCapacity($room, PlacementCategory::Resident, $occupancy);
            [$bed, $locker] = $this->resolveResources($room, $bedId, $lockerId, $occupancy);

            if ($dryRun) {
                return null;
            }

            $today = self::today();
            $placement->fill([
                'status' => PlacementStatus::Ended,
                'check_out_on' => $today,
                'end_reason' => EndReason::Mutation,
                'ended_by' => $actorId,
            ]);
            $placement->save();

            $moved = new Placement;
            $moved->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $room->organization_id,
                'dormitory_id' => $room->dormitory_id,
                'room_id' => $room->id,
                'bed_id' => $bed?->id,
                'locker_id' => $locker?->id,
                'membership_id' => $placement->membership_id,
                'category' => PlacementCategory::Resident,
                'status' => PlacementStatus::Active,
                'check_in_on' => $today,
                'created_by' => $actorId,
                'checked_in_by' => $actorId,
            ]);
            $moved->save();

            // Sesudah penempatan baru tersimpan: pendengar (Finance) melihat santri masih tinggal di kamar barunya.
            event(new ResidencyEnded($tenantId, $placement->membership_id, $today->toDateString()));

            return $moved;
        }));
    }

    /**
     * Ganti bed/loker penempatan Aktif/Direncanakan di kamar yang sama (mis. bed rusak). Bukan mutasi.
     *
     * @throws PlacementException
     */
    public function changeResources(string $tenantId, string $placementId, ?string $bedId, ?string $lockerId, string $actorId): Placement
    {
        return $this->guarded(fn (): Placement => DB::transaction(function () use ($tenantId, $placementId, $bedId, $lockerId): Placement {
            [$placement, $room] = $this->lockPlacement($tenantId, $placementId);

            if (! $placement->isHolding()) {
                throw PlacementException::make('form', 'Penempatan ini sudah selesai atau dibatalkan.');
            }

            [$bed, $locker] = $this->resolveResources($room, $bedId, $lockerId, RoomOccupancy::forRoom($room->id, $placement->id));
            $before = ['bed' => $placement->bed_id, 'locker' => $placement->locker_id];

            $placement->fill(['bed_id' => $bed?->id, 'locker_id' => $locker?->id]);

            if (! $placement->isDirty()) {
                return $placement;
            }

            $placement->save();

            $this->record(DormitoryAuditAction::PLACEMENT_RESOURCE_CHANGED, $placement, $room, $bed, $locker, [
                'bed_sebelumnya' => $before['bed'] === null ? null : Bed::query()->withoutGlobalScope('tenant')->whereKey($before['bed'])->value('code'),
                'loker_sebelumnya' => $before['locker'] === null ? null : Locker::query()->withoutGlobalScope('tenant')->whereKey($before['locker'])->value('code'),
            ]);

            return $placement;
        }));
    }

    /**
     * Saran bed/loker untuk form: kode terkecil yang aktif, tersedia, dan belum dipegang (OD-DM-05).
     *
     * @return array{bed: string|null, locker: string|null}
     */
    public static function suggestion(Room $room, ?string $exceptPlacementId = null): array
    {
        $occupancy = RoomOccupancy::forRoom($room->id, $exceptPlacementId);

        return [
            'bed' => self::firstFree(ResourceKind::Bed, $room->id, array_keys($occupancy['beds']))?->id,
            'locker' => self::firstFree(ResourceKind::Locker, $room->id, array_keys($occupancy['lockers']))?->id,
        ];
    }

    // ── Aturan ────────────────────────────────────────────────────────────────

    /**
     * @return array{Room, Building, Dormitory}
     *
     * @throws PlacementException
     */
    private function lockRoom(string $tenantId, string $roomId): array
    {
        $room = Room::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($roomId)->lockForUpdate()->first();

        if (! $room instanceof Room) {
            throw PlacementException::make('form', 'Kamar tidak ditemukan.');
        }

        $building = Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->firstOrFail();
        $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->firstOrFail();

        if (! $room->is_active || ! $building->is_active || ! $dormitory->is_active) {
            throw PlacementException::make('form', "Kamar {$room->code}, gedungnya, atau asramanya nonaktif, jadi tidak menerima penghuni.");
        }

        return [$room, $building, $dormitory];
    }

    /**
     * Kunci kamar dulu, baru penempatannya (urutan sama dengan place(), agar tidak saling tunggu).
     *
     * @return array{Placement, Room, Building, Dormitory}
     *
     * @throws PlacementException
     */
    private function lockPlacement(string $tenantId, string $placementId): array
    {
        $roomId = Placement::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($placementId)->value('room_id');

        if (! is_string($roomId)) {
            throw PlacementException::make('form', 'Penempatan tidak ditemukan.');
        }

        $room = Room::query()->withoutGlobalScope('tenant')->whereKey($roomId)->lockForUpdate()->firstOrFail();
        $placement = Placement::query()->withoutGlobalScope('tenant')->whereKey($placementId)->lockForUpdate()->firstOrFail();
        $building = Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->firstOrFail();
        $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->firstOrFail();

        // Check-in/ganti bed butuh kamar terbuka; batal & check-out tetap boleh di kamar nonaktif.
        return [$placement, $room, $building, $dormitory];
    }

    /**
     * Jenis kelamin, komposisi, kelayakan. Mengembalikan nama orang untuk pesan.
     *
     * @throws PlacementException
     */
    private function assertPersonFits(string $tenantId, Dormitory $dormitory, Building $building, Room $room, string $membershipId, PlacementCategory $category): string
    {
        if (! $room->is_active || ! $building->is_active || ! $dormitory->is_active) {
            throw PlacementException::make('form', "Kamar {$room->code}, gedungnya, atau asramanya nonaktif, jadi tidak menerima penghuni.");
        }

        $person = $this->eligibility->people($tenantId, [$membershipId])[$membershipId] ?? null;

        if ($person === null) {
            throw PlacementException::make('membership_id', 'Orang ini tidak ditemukan di yayasan.');
        }

        if ($person['gender'] === null) {
            throw PlacementException::make('membership_id', "Jenis kelamin {$person['name']} belum diisi. Lengkapi di data siswa/pegawai dulu.");
        }

        if ($person['gender'] !== $building->gender) {
            $for = $building->gender->value === 'L' ? 'putra' : 'putri';

            throw PlacementException::make('membership_id', "Kamar {$room->code} untuk {$for}, sedangkan {$person['name']} ".($for === 'putra' ? 'putri' : 'putra').'.');
        }

        if ($category === PlacementCategory::Supervisor && $room->composition !== RoomComposition::WithSupervisor) {
            throw PlacementException::make('membership_id', "Kamar {$room->code} khusus santri; pengawas hanya bisa tinggal di kamar santri + pengawas.");
        }

        if (! $this->eligibility->isEligible($tenantId, $dormitory->organization_id, $membershipId, $category)) {
            throw PlacementException::make('membership_id', $category === PlacementCategory::Resident
                ? "{$person['name']} tidak punya pendaftaran aktif di lembaga milik ".$this->ownerName($dormitory).'.'
                : "{$person['name']} bukan pegawai aktif.");
        }

        return $person['name'];
    }

    /**
     * @throws PlacementException
     */
    private function assertNoConflict(string $tenantId, string $membershipId, string $name, bool $plan, string $roomId): void
    {
        $active = $this->holdingOf($tenantId, $membershipId, PlacementStatus::Active, null);
        $planned = $this->holdingOf($tenantId, $membershipId, PlacementStatus::Planned, null);

        if ($planned !== null) {
            throw PlacementException::make('membership_id', $plan
                ? "{$name} sudah punya rencana di {$planned}. Batalkan dulu rencana itu."
                : "{$name} sudah punya rencana di {$planned}. Check-in rencana itu atau batalkan dulu.");
        }

        if ($active === null) {
            return;
        }

        if (! $plan) {
            throw PlacementException::make('membership_id', "{$name} sudah tinggal di {$active}.");
        }

        $activeRoom = Placement::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)->where('status', PlacementStatus::Active->value)->value('room_id');

        if ($activeRoom === $roomId) {
            throw PlacementException::make('membership_id', "{$name} sudah tinggal di kamar ini.");
        }
    }

    /**
     * Label "kamar A-101 (Asrama Putra Unit 1)" dari penempatan orang itu dengan status tertentu, atau null.
     */
    private function holdingOf(string $tenantId, string $membershipId, PlacementStatus $status, ?string $exceptId): ?string
    {
        $row = DB::table('dm_placements as pl')
            ->join('dm_rooms as r', 'r.id', '=', 'pl.room_id')
            ->join('dm_dormitories as d', 'd.id', '=', 'pl.dormitory_id')
            ->where('pl.tenant_id', $tenantId)
            ->where('pl.membership_id', $membershipId)
            ->where('pl.status', $status->value)
            ->when($exceptId !== null, fn ($query) => $query->where('pl.id', '<>', $exceptId))
            ->first(['r.code', 'd.name']);

        return $row === null ? null : "kamar {$row->code} ({$row->name})";
    }

    /**
     * @param  array{active: int, planned: int, residents: int, supervisors: int, beds: array<string, string>, lockers: array<string, string>}  $occupancy  tanpa penempatan itu sendiri
     *
     * @throws PlacementException
     */
    private function assertCapacity(Room $room, PlacementCategory $category, array $occupancy): void
    {
        $capacity = RoomCapacity::forRoom($room);
        $taken = $occupancy['active'] + $occupancy['planned'];

        if ($capacity['effective'] === 0) {
            throw PlacementException::make('form', "Kamar {$room->code} belum punya bed/loker yang tersedia.");
        }

        if ($category === PlacementCategory::Supervisor) {
            $slots = min($capacity['supervisor_slots'], $capacity['effective']);

            if ($occupancy['supervisors'] >= $slots) {
                throw PlacementException::make('form', "Slot pengawas kamar {$room->code} sudah penuh ({$occupancy['supervisors']} dari {$slots}).");
            }
        } elseif ($occupancy['residents'] >= $capacity['resident_slots']) {
            throw PlacementException::make('form', "Kamar {$room->code} penuh untuk santri ({$occupancy['residents']} dari {$capacity['resident_slots']}).");
        }

        if ($taken >= $capacity['effective']) {
            throw PlacementException::make('form', "Kamar {$room->code} penuh ({$taken} dari {$capacity['effective']}).");
        }
    }

    /**
     * Bed & loker yang dipegang. Kosong + diwajibkan dasar kapasitas → kode terkecil yang bebas; kosong + tidak
     * diwajibkan → tidak dipegang.
     *
     * @param  array{active: int, planned: int, residents: int, supervisors: int, beds: array<string, string>, lockers: array<string, string>}  $occupancy  tanpa penempatan itu sendiri
     * @return array{Bed|null, Locker|null}
     *
     * @throws PlacementException
     */
    private function resolveResources(Room $room, ?string $bedId, ?string $lockerId, array $occupancy): array
    {
        $bed = $this->resource(ResourceKind::Bed, $room, $bedId, $room->capacity_basis->requiresBed(), array_keys($occupancy['beds']));
        $locker = $this->resource(ResourceKind::Locker, $room, $lockerId, $room->capacity_basis->requiresLocker(), array_keys($occupancy['lockers']));

        return [$bed instanceof Bed ? $bed : null, $locker instanceof Locker ? $locker : null];
    }

    /**
     * @param  list<string>  $held
     *
     * @throws PlacementException
     */
    private function resource(ResourceKind $kind, Room $room, ?string $id, bool $required, array $held): ?RoomResource
    {
        $field = $kind === ResourceKind::Bed ? 'bed_id' : 'locker_id';
        $label = mb_strtolower($kind->label());

        if ($id === null || $id === '') {
            if (! $required) {
                return null;
            }

            $free = self::firstFree($kind, $room->id, $held);

            if ($free === null) {
                throw PlacementException::make($field, "Tidak ada {$label} yang kosong di kamar {$room->code}.");
            }

            return $free;
        }

        $model = $kind->modelClass();
        $resource = $model::query()->withoutGlobalScope('tenant')->where('room_id', $room->id)->whereKey($id)->first();

        if (! $resource instanceof RoomResource) {
            throw PlacementException::make($field, ucfirst($label)." tidak ditemukan di kamar {$room->code}.");
        }

        if (! $resource->is_active) {
            throw PlacementException::make($field, "{$kind->label()} {$resource->code} nonaktif.");
        }

        if ($resource->status !== ResourceStatus::Available) {
            throw PlacementException::make($field, "{$kind->label()} {$resource->code} sedang ".mb_strtolower($resource->unavailable_reason?->label() ?? 'tidak tersedia').'.');
        }

        if (in_array($resource->id, $held, true)) {
            throw PlacementException::make($field, "{$kind->label()} {$resource->code} sudah dipakai atau dipesan penghuni lain.");
        }

        return $resource;
    }

    /**
     * @param  list<string>  $held
     */
    private static function firstFree(ResourceKind $kind, string $roomId, array $held): ?RoomResource
    {
        $model = $kind->modelClass();
        $resource = $model::query()->withoutGlobalScope('tenant')
            ->where('room_id', $roomId)
            ->where('is_active', true)
            ->where('status', ResourceStatus::Available->value)
            ->when($held !== [], fn ($query) => $query->whereNotIn('id', $held))
            ->orderBy('code')
            ->first();

        return $resource instanceof RoomResource ? $resource : null;
    }

    /**
     * Tanggal masuk: ≤ hari ini, ≥ 1 tahun lalu, dan tidak sebelum tanggal keluar penempatan terakhir orang itu
     * (riwayat tidak tumpang tindih).
     *
     * @throws PlacementException
     */
    private function assertCheckInDate(string $tenantId, string $membershipId, CarbonImmutable $date): void
    {
        $today = self::today();

        if ($date->gt($today)) {
            throw PlacementException::make('date', 'Tanggal masuk tidak boleh di masa depan.');
        }

        if ($date->lt($today->subDays(self::BACKDATE_DAYS))) {
            throw PlacementException::make('date', 'Tanggal masuk paling jauh 1 tahun ke belakang.');
        }

        $lastOut = Placement::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('membership_id', $membershipId)
            ->where('status', PlacementStatus::Ended->value)
            ->max('check_out_on');

        if (is_string($lastOut) && $date->lt(CarbonImmutable::parse($lastOut))) {
            throw PlacementException::make('date', 'Tanggal masuk tidak boleh sebelum tanggal keluar terakhirnya ('.self::display(CarbonImmutable::parse($lastOut)).').');
        }
    }

    private function ownerName(Dormitory $dormitory): string
    {
        return (string) DB::table('organizations')->where('id', $dormitory->organization_id)->value('name');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     *
     * @throws PlacementException
     */
    private function guarded(callable $action): mixed
    {
        try {
            return $action();
        } catch (UniqueConstraintViolationException) {
            throw PlacementException::make('form', 'Data kamar berubah karena petugas lain menyimpan bersamaan. Muat ulang halaman lalu coba lagi.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $action, Placement $placement, Room $room, ?Bed $bed, ?Locker $locker, array $extra): void
    {
        $this->audit->record(
            $action,
            DormitoryAuditAction::SUBJECT_PLACEMENT,
            $placement->id,
            array_merge([
                'kamar' => $room->code,
                'kategori' => $placement->category->value,
                'bed' => $bed?->code,
                'loker' => $locker?->code,
            ], $extra),
            organizationId: $placement->organization_id,
            tenantId: $placement->tenant_id,
        );
    }

    /**
     * @throws PlacementException
     */
    private static function date(?string $value, string $field, string $emptyMessage): CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            throw PlacementException::make($field, $emptyMessage);
        }

        // Tanggal kalender tanpa jam, diurai di zona waktu aplikasi — sama dengan kolom `date` yang dibaca Eloquent —
        // agar perbandingan dengan tanggal tersimpan tidak bergeser (WIB vs UTC).
        $text = trim($value);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $text) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $text) {
            throw PlacementException::make($field, 'Format tanggal tidak dikenali.');
        }

        return $date;
    }

    /**
     * @throws PlacementException
     */
    private static function plannedDate(string $value): CarbonImmutable
    {
        $date = self::date($value, 'date', 'Isi rencana tanggal masuk.');
        $today = self::today();

        if ($date->lt($today->subDays(self::BACKDATE_DAYS)) || $date->gt($today->addDays(self::PLAN_AHEAD_DAYS))) {
            throw PlacementException::make('date', 'Rencana tanggal masuk paling jauh 1 tahun dari hari ini.');
        }

        return $date;
    }

    /**
     * @throws PlacementException
     */
    private static function note(?string $value): ?string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw PlacementException::make('note', 'Catatan paling panjang '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return $note === '' ? null : $note;
    }

    /**
     * "Hari ini" menurut zona waktu bisnis (EDUCORE_TIMEZONE), sebagai tanggal di zona waktu aplikasi agar bisa
     * dibandingkan langsung dengan tanggal dari form & database.
     */
    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', BusinessClock::todayString()) ?: CarbonImmutable::today();
    }

    private static function display(CarbonImmutable $date): string
    {
        return $date->format('d/m/Y');
    }
}
