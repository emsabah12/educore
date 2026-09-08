<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;

final class TenantAddon extends Model
{
    use HasUuidV7;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED_READONLY = 'locked_readonly';

    public const STATUS_LOCKED_HIDDEN = 'locked_hidden';

    protected $table = 'tenant_addons';

    protected $fillable = [
        'tenant_id',
        'addon_id',
        'status',
        'locked_at',
        'readonly_until',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'addon_id' => 'string',
        'locked_at' => 'immutable_datetime',
        'readonly_until' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
