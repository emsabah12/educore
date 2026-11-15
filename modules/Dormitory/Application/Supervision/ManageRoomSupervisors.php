<?php

namespace Modules\Dormitory\Application\Supervision;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Placement\ResidentEligibility;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Supervision\Exceptions\SupervisionException;
use Modules\Dormitory\Domain\Supervision\RoomSupervisor;

/**
 * Tambah & akhiri penugasan wali kamar (PRD-008 §17.2). Penambahan berjalan di transaksi yang mengunci baris kamar
 * sehingga batas 3 wali kamar per kamar tetap terjaga saat dua petugas menyimpan bersamaan; unique index menjaga
 * orang yang sama tidak dobel di satu kamar.
 */
final class ManageRoomSupervisors
{
    public const MAX_PER_ROOM = 3;

    public const NOTE_MAX_LENGTH = 200;

    public const BACKDATE_DAYS = 365;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResidentEligibility $eligibility,
    ) {}

    /**
     * @throws SupervisionException
     */
    public function assign(string $tenantId, string $roomId, string $membershipId, ?string $date, ?string $note, string $actorId): RoomSupervisor
    {
        $startOn = self::date($date, 'Isi tanggal mulai.');
        $today = self::today();

        if ($startOn->gt($today)) {
            throw SupervisionException::make('date', 'Tanggal mulai tidak boleh di masa depan.');
        }

        if ($startOn->lt($today->subDays(self::BACKDATE_DAYS))) {
            throw SupervisionException::make('date', 'Tanggal mulai paling jauh 1 tahun ke belakang.');
        }

        $note = self::note($note);

        try {
            return DB::transaction(function () use ($tenantId, $roomId, $membershipId, $startOn, $note, $actorId): RoomSupervisor {
                $room = Room::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($roomId)->lockForUpdate()->first();

                if (! $room instanceof Room) {
                    throw SupervisionException::make('form', 'Kamar tidak ditemukan.');
                }

                $building = Building::query()->withoutGlobalScope('tenant')->whereKey($room->building_id)->firstOrFail();
                $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->whereKey($room->dormitory_id)->firstOrFail();

                if (! $room->is_active || ! $building->is_active || ! $dormitory->is_active) {
                    throw SupervisionException::make('form', "Kamar {$room->code}, gedungnya, atau asramanya nonaktif, jadi tidak bisa diberi wali kamar.");
                }

                $name = $this->assertPersonFits($tenantId, $building, $room, $membershipId);

                $active = RoomSupervisor::query()->withoutGlobalScope('tenant')->where('room_id', $room->id)->whereNull('end_on');

                if ((clone $active)->where('membership_id', $membershipId)->exists()) {
                    throw SupervisionException::make('membership_id', "{$name} sudah menjadi wali kamar {$room->code}.");
                }

                if ($active->count() >= self::MAX_PER_ROOM) {
                    throw SupervisionException::make('form', "Kamar {$room->code} sudah punya ".self::MAX_PER_ROOM.' wali kamar. Akhiri salah satu dulu.');
                }

                $supervisor = new RoomSupervisor;
                $supervisor->fill([
                    'tenant_id' => $tenantId,
                    'organization_id' => $room->organization_id,
                    'dormitory_id' => $room->dormitory_id,
                    'room_id' => $room->id,
                    'membership_id' => $membershipId,
                    'start_on' => $startOn,
                    'note' => $note,
                    'created_by' => $actorId,
                ]);
                $supervisor->save();

                $this->record(DormitoryAuditAction::SUPERVISOR_ASSIGNED, $supervisor, $room, $name, ['mulai' => $startOn->toDateString()]);

                return $supervisor;
            });
        } catch (UniqueConstraintViolationException) {
            throw SupervisionException::make('form', 'Data wali kamar berubah karena petugas lain menyimpan bersamaan. Muat ulang halaman lalu coba lagi.');
        }
    }

    /**
     * @throws SupervisionException
     */
    public function end(string $tenantId, string $supervisorId, ?string $date, ?string $note, string $actorId): RoomSupervisor
    {
        $endOn = self::date($date, 'Isi tanggal selesai.');
        $note = self::note($note);

        if ($endOn->gt(self::today())) {
            throw SupervisionException::make('date', 'Tanggal selesai tidak boleh di masa depan.');
        }

        return DB::transaction(function () use ($tenantId, $supervisorId, $endOn, $note, $actorId): RoomSupervisor {
            $supervisor = RoomSupervisor::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($supervisorId)->lockForUpdate()->first();

            if (! $supervisor instanceof RoomSupervisor) {
                throw SupervisionException::make('form', 'Penugasan wali kamar tidak ditemukan.');
            }

            if (! $supervisor->isActive()) {
                throw SupervisionException::make('form', 'Penugasan ini sudah diakhiri.');
            }

            if ($endOn->lt($supervisor->start_on)) {
                throw SupervisionException::make('date', 'Tanggal selesai tidak boleh sebelum tanggal mulai ('.$supervisor->start_on->format('d/m/Y').').');
            }

            $supervisor->fill([
                'end_on' => $endOn,
                'note' => $note ?? $supervisor->note,
                'ended_by' => $actorId,
            ]);
            $supervisor->save();

            $room = Room::query()->withoutGlobalScope('tenant')->whereKey($supervisor->room_id)->firstOrFail();
            $name = $this->eligibility->people($tenantId, [$supervisor->membership_id])[$supervisor->membership_id]['name'] ?? '';
            $this->record(DormitoryAuditAction::SUPERVISOR_ENDED, $supervisor, $room, $name, ['selesai' => $endOn->toDateString()]);

            return $supervisor;
        });
    }

    /**
     * Pegawai aktif dengan jenis kelamin sesuai gedung (OD-DM-03, OD-DM-08). Mengembalikan nama untuk pesan.
     *
     * @throws SupervisionException
     */
    private function assertPersonFits(string $tenantId, Building $building, Room $room, string $membershipId): string
    {
        $person = $this->eligibility->people($tenantId, [$membershipId])[$membershipId] ?? null;

        if ($person === null) {
            throw SupervisionException::make('membership_id', 'Orang ini tidak ditemukan di yayasan.');
        }

        if (! $this->eligibility->isEligible($tenantId, $room->organization_id, $membershipId, PlacementCategory::Supervisor)) {
            throw SupervisionException::make('membership_id', "{$person['name']} bukan pegawai aktif.");
        }

        if ($person['gender'] === null) {
            throw SupervisionException::make('membership_id', "Jenis kelamin {$person['name']} belum diisi. Lengkapi di data pegawai dulu.");
        }

        if ($person['gender'] !== $building->gender) {
            $for = $building->gender->value === 'L' ? 'putra' : 'putri';

            throw SupervisionException::make('membership_id', "Kamar {$room->code} untuk {$for}; wali kamarnya harus ".($for === 'putra' ? 'laki-laki' : 'perempuan').'.');
        }

        return $person['name'];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $action, RoomSupervisor $supervisor, Room $room, string $name, array $extra): void
    {
        $this->audit->record(
            $action,
            DormitoryAuditAction::SUBJECT_SUPERVISOR,
            $supervisor->id,
            array_merge(['kamar' => $room->code, 'wali_kamar' => $name], $extra),
            organizationId: $supervisor->organization_id,
            tenantId: $supervisor->tenant_id,
        );
    }

    /**
     * Tanggal kalender (Y-m-d) di zona waktu aplikasi, sama dengan kolom `date` yang dibaca Eloquent.
     *
     * @throws SupervisionException
     */
    private static function date(?string $value, string $emptyMessage): CarbonImmutable
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw SupervisionException::make('date', $emptyMessage);
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $text) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $text) {
            throw SupervisionException::make('date', 'Format tanggal tidak dikenali.');
        }

        return $date;
    }

    /**
     * @throws SupervisionException
     */
    private static function note(?string $value): ?string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw SupervisionException::make('note', 'Keterangan paling panjang '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return $note === '' ? null : $note;
    }

    /** "Hari ini" menurut zona waktu bisnis (EDUCORE_TIMEZONE). */
    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', BusinessClock::todayString()) ?: CarbonImmutable::today();
    }
}
