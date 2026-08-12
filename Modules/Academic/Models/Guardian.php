<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Guardian extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'guardians';

    protected $fillable = [
        'membership_id',
    ];

    protected $casts = [
        'tenant_id' => 'string',
        'membership_id' => 'string',
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
