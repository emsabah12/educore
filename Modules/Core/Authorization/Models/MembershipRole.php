<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MembershipRole extends Model
{
    protected $table = 'membership_roles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'membership_id',
        );
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
        );
    }
}
