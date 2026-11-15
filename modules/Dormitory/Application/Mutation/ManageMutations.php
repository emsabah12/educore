<?php

namespace Modules\Dormitory\Application\Mutation;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Placement\ManagePlacements;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Mutation\EmergencyCategory;
use Modules\Dormitory\Domain\Mutation\Exceptions\MutationException;
use Modules\Dormitory\Domain\Mutation\Mutation;
use Modules\Dormitory\Domain\Mutation\MutationStatus;
use Modules\Dormitory\Domain\Mutation\MutationType;
use Modules\Dormitory\Domain\Placement\Exceptions\PlacementException;
use Modules\Dormitory\Domain\Placement\Placement;

/**
 * Mutasi kamar (PRD-008 §8, §18.2): ajukan, setujui (= langsung dilaksanakan), tolak, tarik, darurat, tinjau.
 * Perpindahan penempatannya dikerjakan ManagePlacements::move() di transaksi yang sama, sehingga bila tujuan
 * ternyata penuh tidak ada yang berubah. Hak akses (asal & tujuan, wali kamar) diperiksa controller; aturan
 * pemisahan tugas (pengaju ≠ penyetuju, pelaku ≠ peninjau) diperiksa di sini.
 */
final class ManageMutations
{
    public const REASON_MAX_LENGTH = 500;

    public const EMERGENCY_REASON_MIN_LENGTH = 10;

    public const NOTE_MAX_LENGTH = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManagePlacements $placements,
    ) {}

    /**
     * Ajukan mutasi biasa. Tujuan langsung diperiksa (tanpa menyimpan perpindahan).
     *
     * @throws MutationException
     */
    public function request(string $tenantId, string $placementId, string $toRoomId, ?string $reason, string $actorId): Mutation
    {
        $reason = self::reason($reason, 1, 'Tuliskan alasan mutasi.');

        try {
            return DB::transaction(function () use ($tenantId, $placementId, $toRoomId, $reason, $actorId): Mutation {
                $this->translate(fn () => $this->placements->move($tenantId, $placementId, $toRoomId, null, null, $actorId, dryRun: true));
                $placement = Placement::query()->withoutGlobalScope('tenant')->whereKey($placementId)->firstOrFail();

                $open = Mutation::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)
                    ->where('membership_id', $placement->membership_id)->where('status', MutationStatus::Requested->value)->first();

                if ($open instanceof Mutation) {
                    throw MutationException::make('form', 'Santri ini sudah punya pengajuan mutasi yang menunggu persetujuan. Tarik dulu pengajuan itu.');
                }

                $mutation = $this->newMutation($tenantId, $placement, $toRoomId, MutationType::Regular, MutationStatus::Requested, null, $reason, $actorId);
                $this->record(DormitoryAuditAction::MUTATION_REQUESTED, $mutation);

                return $mutation;
            });
        } catch (UniqueConstraintViolationException) {
            throw MutationException::make('form', 'Santri ini sudah punya pengajuan mutasi yang menunggu persetujuan.');
        }
    }

    /**
     * Setujui = langsung laksanakan (§8.1). Penyetuju bukan pengaju. Gagal (mis. tujuan penuh) → tetap Diajukan.
     *
     * @throws MutationException
     */
    public function approve(string $tenantId, string $mutationId, ?string $bedId, ?string $lockerId, string $actorId): Mutation
    {
        return DB::transaction(function () use ($tenantId, $mutationId, $bedId, $lockerId, $actorId): Mutation {
            $mutation = $this->lockOpen($tenantId, $mutationId);

            if ($mutation->requested_by === $actorId) {
                throw MutationException::make('form', 'Pengajuan Anda sendiri tidak bisa Anda setujui. Minta penyetuju lain.');
            }

            $moved = $this->translate(fn () => $this->placements->move($tenantId, $mutation->placement_id, $mutation->to_room_id, $bedId, $lockerId, $actorId));

            $mutation->fill([
                'status' => MutationStatus::Executed,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'executed_on' => $moved?->check_in_on,
                'new_placement_id' => $moved?->id,
            ]);
            $mutation->save();
            $this->record(DormitoryAuditAction::MUTATION_APPROVED, $mutation);

            return $mutation;
        });
    }

    /**
     * @throws MutationException
     */
    public function reject(string $tenantId, string $mutationId, ?string $note, string $actorId): Mutation
    {
        $note = self::reason($note, 1, 'Tuliskan alasan penolakan.');

        return DB::transaction(function () use ($tenantId, $mutationId, $note, $actorId): Mutation {
            $mutation = $this->lockOpen($tenantId, $mutationId);
            $mutation->fill([
                'status' => MutationStatus::Rejected,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $mutation->save();
            $this->record(DormitoryAuditAction::MUTATION_REJECTED, $mutation);

            return $mutation;
        });
    }

    /**
     * Tarik pengajuan; hanya pengajunya sendiri.
     *
     * @throws MutationException
     */
    public function cancel(string $tenantId, string $mutationId, string $actorId): Mutation
    {
        return DB::transaction(function () use ($tenantId, $mutationId, $actorId): Mutation {
            $mutation = $this->lockOpen($tenantId, $mutationId);

            if ($mutation->requested_by !== $actorId) {
                throw MutationException::make('form', 'Hanya pengaju yang bisa menarik pengajuan ini.');
            }

            $mutation->fill([
                'status' => MutationStatus::Cancelled,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => 'Ditarik pengaju.',
            ]);
            $mutation->save();
            $this->record(DormitoryAuditAction::MUTATION_CANCELLED, $mutation);

            return $mutation;
        });
    }

    /**
     * Mutasi darurat (§8.2): langsung dilaksanakan dengan aturan yang sama; bed/loker = saran otomatis. Pengajuan
     * biasa yang masih terbuka untuk santri ini gugur.
     *
     * @throws MutationException
     */
    public function emergency(string $tenantId, string $placementId, string $toRoomId, ?EmergencyCategory $category, ?string $reason, string $actorId): Mutation
    {
        if ($category === null) {
            throw MutationException::make('emergency_category', 'Pilih kategori darurat.');
        }

        $reason = self::reason($reason, self::EMERGENCY_REASON_MIN_LENGTH, 'Tuliskan alasan mutasi darurat.');

        return DB::transaction(function () use ($tenantId, $placementId, $toRoomId, $category, $reason, $actorId): Mutation {
            $moved = $this->translate(fn () => $this->placements->move($tenantId, $placementId, $toRoomId, null, null, $actorId));
            $placement = Placement::query()->withoutGlobalScope('tenant')->whereKey($placementId)->firstOrFail();
            OpenMutations::lapse($tenantId, $placementId, 'Gugur otomatis: santri sudah dimutasi lewat jalur darurat.');

            $mutation = $this->newMutation($tenantId, $placement, $toRoomId, MutationType::Emergency, MutationStatus::Executed, $category, $reason, $actorId, [
                'decided_by' => $actorId,
                'decided_at' => now(),
                'executed_on' => $moved?->check_in_on,
                'new_placement_id' => $moved?->id,
            ]);
            $this->record(DormitoryAuditAction::MUTATION_EMERGENCY, $mutation);

            return $mutation;
        });
    }

    /**
     * Tandai mutasi darurat Sudah ditinjau. Peninjau bukan pelakunya [OWNER, 2026-11-15].
     *
     * @throws MutationException
     */
    public function review(string $tenantId, string $mutationId, ?string $note, string $actorId): Mutation
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $note));

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw MutationException::make('note', 'Catatan paling panjang '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $mutationId, $note, $actorId): Mutation {
            $mutation = Mutation::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($mutationId)->lockForUpdate()->first();

            if (! $mutation instanceof Mutation || $mutation->type !== MutationType::Emergency) {
                throw MutationException::make('form', 'Mutasi darurat tidak ditemukan.');
            }

            if ($mutation->reviewed_at !== null) {
                throw MutationException::make('form', 'Mutasi darurat ini sudah ditinjau.');
            }

            if ($mutation->requested_by === $actorId) {
                throw MutationException::make('form', 'Mutasi darurat yang Anda lakukan sendiri harus ditinjau orang lain.');
            }

            $mutation->fill([
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $note === '' ? null : $note,
            ]);
            $mutation->save();
            $this->record(DormitoryAuditAction::MUTATION_REVIEWED, $mutation);

            return $mutation;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function newMutation(string $tenantId, Placement $placement, string $toRoomId, MutationType $type, MutationStatus $status, ?EmergencyCategory $category, string $reason, string $actorId, array $extra = []): Mutation
    {
        $toRoom = Room::query()->withoutGlobalScope('tenant')->whereKey($toRoomId)->firstOrFail();

        $mutation = new Mutation;
        $mutation->fill([
            'tenant_id' => $tenantId,
            'organization_id' => $placement->organization_id,
            'dormitory_id' => $placement->dormitory_id,
            'from_room_id' => $placement->room_id,
            'placement_id' => $placement->id,
            'to_organization_id' => $toRoom->organization_id,
            'to_dormitory_id' => $toRoom->dormitory_id,
            'to_room_id' => $toRoom->id,
            'membership_id' => $placement->membership_id,
            'type' => $type,
            'status' => $status,
            'emergency_category' => $category,
            'reason' => $reason,
            'requested_by' => $actorId,
        ]);
        $mutation->fill($extra);
        $mutation->save();

        return $mutation;
    }

    /**
     * @throws MutationException
     */
    private function lockOpen(string $tenantId, string $mutationId): Mutation
    {
        $mutation = Mutation::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($mutationId)->lockForUpdate()->first();

        if (! $mutation instanceof Mutation) {
            throw MutationException::make('form', 'Pengajuan mutasi tidak ditemukan.');
        }

        if ($mutation->status !== MutationStatus::Requested) {
            throw MutationException::make('form', 'Pengajuan ini sudah '.mb_strtolower($mutation->status->label()).'.');
        }

        return $mutation;
    }

    /**
     * Jalankan aksi penempatan; pesan PlacementException diteruskan sebagai MutationException. Isian yang menyangkut
     * orang/kamar diarahkan ke pilihan kamar tujuan.
     *
     * @param  callable(): ?Placement  $action
     *
     * @throws MutationException
     */
    private function translate(callable $action): ?Placement
    {
        try {
            return $action();
        } catch (PlacementException $exception) {
            $field = in_array($exception->field, ['bed_id', 'locker_id', 'form'], true) ? $exception->field : 'to_room_id';

            throw MutationException::make($field, $exception->getMessage());
        }
    }

    private function record(string $action, Mutation $mutation): void
    {
        $rooms = Room::query()->withoutGlobalScope('tenant')->whereKey([$mutation->from_room_id, $mutation->to_room_id])->pluck('code', 'id');
        $name = (string) DB::table('memberships as m')->join('persons as p', 'p.id', '=', 'm.person_id')->where('m.id', $mutation->membership_id)->value('p.name');

        $this->audit->record(
            $action,
            DormitoryAuditAction::SUBJECT_MUTATION,
            $mutation->id,
            array_filter([
                'santri' => $name,
                'dari' => $rooms[$mutation->from_room_id] ?? null,
                'ke' => $rooms[$mutation->to_room_id] ?? null,
                'jenis' => $mutation->type->value,
                'kategori' => $mutation->emergency_category?->value,
            ], fn ($value): bool => $value !== null),
            organizationId: $mutation->organization_id,
            tenantId: $mutation->tenant_id,
        );
    }

    /**
     * @throws MutationException
     */
    private static function reason(?string $value, int $min, string $emptyMessage): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($text === '') {
            throw MutationException::make('reason', $emptyMessage);
        }

        if (mb_strlen($text) < $min) {
            throw MutationException::make('reason', "Alasan minimal {$min} karakter.");
        }

        if (mb_strlen($text) > self::REASON_MAX_LENGTH) {
            throw MutationException::make('reason', 'Alasan paling panjang '.self::REASON_MAX_LENGTH.' karakter.');
        }

        return $text;
    }
}
