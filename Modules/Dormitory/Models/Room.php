<?php

declare(strict_types=1);

namespace Modules\Dormitory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;
use Modules\Dormitory\Domain\Enums\RoomCapacityBasis;

final class Room extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'rooms';

    protected $fillable = [
        'building_id',
        'name',
        'code',
        'capacity_basis',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'building_id' => 'string',
        'capacity_basis' => RoomCapacityBasis::class,
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
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
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(
            Building::class,
            'building_id',
        );
    }
}
