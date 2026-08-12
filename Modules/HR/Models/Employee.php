<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Employee extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'employees';

    protected $fillable = [
        'membership_id',
        'nip',
        'jabatan',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'membership_id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
    ];

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
}
