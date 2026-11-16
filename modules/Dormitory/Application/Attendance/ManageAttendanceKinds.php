<?php

namespace Modules\Dormitory\Application\Attendance;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Attendance\AttendanceException;
use Modules\Dormitory\Domain\Attendance\AttendanceKind;
use Modules\Dormitory\Domain\Facility\Dormitory;

/**
 * Jenis absen per asrama (PRD-008 §22.1): mis. "Subuh", "Malam".
 *
 * Aturan [ASUMSI disetujui owner 2026-11-16]:
 * - Nama wajib, maks. 30 karakter, unik per asrama (tanpa membedakan huruf besar/kecil).
 * - Maks. 5 jenis aktif per asrama; bisa diurutkan, dinonaktifkan/diaktifkan kembali.
 * - Jenis yang sudah dipakai sesi absen tidak bisa dihapus (cukup nonaktifkan).
 * - Hak akses (`dormitory.facilities.manage` di pemilik asrama) dicek pemanggil.
 *
 * @phpstan-type KindRow array{id: string, name: string, is_active: bool, used: bool}
 */
final class ManageAttendanceKinds
{
    public const NAME_MAX_LENGTH = 30;

    public const MAX_ACTIVE = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<KindRow>
     */
    public function forDormitory(string $dormitoryId, bool $activeOnly = false): array
    {
        $used = DB::table('dm_attendance_sessions')->where('dormitory_id', $dormitoryId)->distinct()->pluck('kind_id')
            ->map(fn (mixed $id): string => (string) $id)->flip();
        $rows = [];

        foreach (AttendanceKind::query()->withoutGlobalScope('tenant')
            ->where('dormitory_id', $dormitoryId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $kind) {
            $rows[] = ['id' => $kind->id, 'name' => $kind->name, 'is_active' => $kind->is_active, 'used' => $used->has($kind->id)];
        }

        return $rows;
    }

    /**
     * @throws AttendanceException
     */
    public function create(string $tenantId, string $dormitoryId, string $name): AttendanceKind
    {
        $name = self::cleanName($name);

        return $this->guardUnique(fn (): AttendanceKind => DB::transaction(function () use ($tenantId, $dormitoryId, $name): AttendanceKind {
            $dormitory = $this->lockDormitory($tenantId, $dormitoryId);
            $this->assertNameFree($dormitory->id, $name, null);
            $this->assertActiveRoom($dormitory->id);

            $kind = new AttendanceKind;
            $kind->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $dormitory->organization_id,
                'dormitory_id' => $dormitory->id,
                'name' => $name,
                'position' => (int) AttendanceKind::query()->withoutGlobalScope('tenant')->where('dormitory_id', $dormitory->id)->max('position') + 1,
                'is_active' => true,
            ]);
            $kind->save();

            $this->record($dormitory, $kind, 'ditambahkan');

            return $kind;
        }));
    }

    /**
     * @throws AttendanceException
     */
    public function rename(string $tenantId, string $kindId, string $name): void
    {
        $name = self::cleanName($name);

        $this->guardUnique(fn () => DB::transaction(function () use ($tenantId, $kindId, $name): void {
            [$dormitory, $kind] = $this->lockKind($tenantId, $kindId);

            if ($kind->name === $name) {
                return;
            }

            $this->assertNameFree($dormitory->id, $name, $kind->id);
            $old = $kind->name;
            $kind->name = $name;
            $kind->save();

            $this->record($dormitory, $kind, 'diubah', ['nama_lama' => $old]);
        }));
    }

    /**
     * @throws AttendanceException
     */
    public function setActive(string $tenantId, string $kindId, bool $active): void
    {
        DB::transaction(function () use ($tenantId, $kindId, $active): void {
            [$dormitory, $kind] = $this->lockKind($tenantId, $kindId);

            if ($kind->is_active === $active) {
                return;
            }

            if ($active) {
                $this->assertActiveRoom($dormitory->id);
            }

            $kind->is_active = $active;
            $kind->save();

            $this->record($dormitory, $kind, $active ? 'diaktifkan kembali' : 'dinonaktifkan');
        });
    }

    /**
     * Geser satu tingkat ke atas (-1) atau ke bawah (+1) di antara jenis asrama itu.
     *
     * @throws AttendanceException
     */
    public function move(string $tenantId, string $kindId, int $direction): void
    {
        DB::transaction(function () use ($tenantId, $kindId, $direction): void {
            [$dormitory, $kind] = $this->lockKind($tenantId, $kindId);
            $ids = array_column($this->forDormitory($dormitory->id), 'id');
            $index = array_search($kind->id, $ids, true);
            $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

            if ($target === null || $target < 0 || $target >= count($ids)) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            foreach ($ids as $position => $id) {
                AttendanceKind::query()->withoutGlobalScope('tenant')->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    /**
     * @throws AttendanceException
     */
    public function delete(string $tenantId, string $kindId): void
    {
        DB::transaction(function () use ($tenantId, $kindId): void {
            [$dormitory, $kind] = $this->lockKind($tenantId, $kindId);

            if (DB::table('dm_attendance_sessions')->where('kind_id', $kind->id)->exists()) {
                throw AttendanceException::make('form', "Jenis absen {$kind->name} sudah dipakai, jadi tidak bisa dihapus. Nonaktifkan saja.");
            }

            $kind->delete();
            $this->record($dormitory, $kind, 'dihapus');
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw AttendanceException::make('name', 'Nama jenis absen wajib diisi.');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw AttendanceException::make('name', 'Nama jenis absen maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        return $name;
    }

    private function lockDormitory(string $tenantId, string $dormitoryId): Dormitory
    {
        $dormitory = Dormitory::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($dormitoryId)->lockForUpdate()->first();

        if (! $dormitory instanceof Dormitory) {
            throw AttendanceException::make('form', 'Asrama tidak ditemukan.');
        }

        return $dormitory;
    }

    /**
     * @return array{0: Dormitory, 1: AttendanceKind}
     */
    private function lockKind(string $tenantId, string $kindId): array
    {
        $kind = AttendanceKind::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($kindId)->first();

        if (! $kind instanceof AttendanceKind) {
            throw AttendanceException::make('form', 'Jenis absen tidak ditemukan.');
        }

        // Kunci asrama dulu agar batas jumlah aktif & urutan tidak balapan.
        $dormitory = $this->lockDormitory($tenantId, $kind->dormitory_id);
        $kind = AttendanceKind::query()->withoutGlobalScope('tenant')->whereKey($kind->id)->lockForUpdate()->firstOrFail();

        return [$dormitory, $kind];
    }

    private function assertNameFree(string $dormitoryId, string $name, ?string $exceptId): void
    {
        $taken = AttendanceKind::query()->withoutGlobalScope('tenant')
            ->where('dormitory_id', $dormitoryId)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw AttendanceException::make('name', "Jenis absen \"{$name}\" sudah ada di asrama ini.");
        }
    }

    /** Batas jumlah jenis aktif (dicek sebelum menambah/mengaktifkan satu lagi). */
    private function assertActiveRoom(string $dormitoryId): void
    {
        $active = AttendanceKind::query()->withoutGlobalScope('tenant')->where('dormitory_id', $dormitoryId)->where('is_active', true)->count();

        if ($active >= self::MAX_ACTIVE) {
            throw AttendanceException::make('name', 'Paling banyak '.self::MAX_ACTIVE.' jenis absen aktif per asrama. Nonaktifkan salah satu dulu.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function guardUnique(callable $action): mixed
    {
        try {
            return $action();
        } catch (UniqueConstraintViolationException) {
            throw AttendanceException::make('name', 'Nama jenis absen ini baru saja dipakai. Pilih nama lain.');
        }
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function record(Dormitory $dormitory, AttendanceKind $kind, string $change, array $extra = []): void
    {
        $this->audit->record(
            DormitoryAuditAction::ATTENDANCE_KIND_CHANGED,
            DormitoryAuditAction::SUBJECT_DORMITORY,
            $dormitory->id,
            ['asrama' => $dormitory->code, 'jenis' => $kind->name, 'perubahan' => $change] + $extra,
            organizationId: $dormitory->organization_id,
            tenantId: $dormitory->tenant_id,
        );
    }
}
