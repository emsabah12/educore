<?php

declare(strict_types=1);

namespace Modules\Dormitory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Bed extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'beds';

    protected $fillable = [
        'room_id',
        'code',
        'is_usable',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'room_id' => 'string',
        'is_usable' => 'boolean',
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
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(
            Room::class,
            'room_id',
        );
    }
}
