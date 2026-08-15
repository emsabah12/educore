<?php

declare(strict_types=1);

namespace Modules\Dormitory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Modules\Dormitory\Domain\Enums\PlacementStatus;
use Modules\Dormitory\Domain\Enums\ResidentCategory;

final class ResidentPlacement extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'resident_placements';

    protected $fillable = [
        'membership_id',
        'room_id',
        'bed_id',
        'locker_id',
        'resident_category',
        'status',
        'planned_at',
        'checked_in_at',
        'ended_at',
        'cancelled_at',
        'end_reason',
        'cancellation_reason',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'membership_id' => 'string',
        'room_id' => 'string',
        'bed_id' => 'string',
        'locker_id' => 'string',
        'resident_category' => ResidentCategory::class,
        'status' => PlacementStatus::class,
        'planned_at' => 'immutable_datetime',
        'checked_in_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class,
            'tenant_id',
        );
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'membership_id',
        );
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(
            Room::class,
            'room_id',
        );
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(
            Bed::class,
            'bed_id',
        );
    }

    /**
     * @return BelongsTo<Locker, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(
            Locker::class,
            'locker_id',
        );
    }
}
