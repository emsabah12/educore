<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Membership extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'memberships';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'role',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * User yang memiliki membership ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'id'
        );
    }
}
