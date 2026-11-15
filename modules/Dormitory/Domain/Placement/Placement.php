<?php

namespace Modules\Dormitory\Domain\Placement;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Dormitory\Domain\Facility\Bed;
use Modules\Dormitory\Domain\Facility\Locker;
use Modules\Dormitory\Domain\Facility\Room;

/**
 * Penempatan seseorang di kamar asrama (PRD-008 §5, §16). Tidak pernah dipindah kamar: pindah = penempatan lama
 * Selesai + penempatan baru (mutasi, DM-4). Jangan membuat/mengubah langsung; pakai
 * Modules\Dormitory\Application\Placement\ManagePlacements.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $room_id
 * @property string|null $bed_id
 * @property string|null $locker_id
 * @property string $membership_id
 * @property PlacementCategory $category
 * @property PlacementStatus $status
 * @property CarbonImmutable|null $planned_on
 * @property CarbonImmutable|null $check_in_on
 * @property CarbonImmutable|null $check_out_on
 * @property EndReason|null $end_reason
 * @property string|null $cancel_reason
 * @property string|null $note
 * @property string|null $created_by
 * @property string|null $checked_in_by
 * @property string|null $ended_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Room $room
 */
class Placement extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_placements';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'dormitory_id',
        'room_id',
        'bed_id',
        'locker_id',
        'membership_id',
        'category',
        'status',
        'planned_on',
        'check_in_on',
        'check_out_on',
        'end_reason',
        'cancel_reason',
        'note',
        'created_by',
        'checked_in_by',
        'ended_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PlacementCategory::class,
            'status' => PlacementStatus::class,
            'end_reason' => EndReason::class,
            'planned_on' => 'immutable_date',
            'check_in_on' => 'immutable_date',
            'check_out_on' => 'immutable_date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function owningTenantId(): string
    {
        return $this->tenant_id;
    }

    public function owningOrganizationId(): string
    {
        return $this->organization_id;
    }

    public function isHolding(): bool
    {
        return in_array($this->status, [PlacementStatus::Planned, PlacementStatus::Active], true);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    /**
     * @return BelongsTo<Locker, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }
}
