<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Identity\Models\User;


final class Membership extends Model
{
    use HasUuids;


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

    public function membershipRoles(): HasMany
    {
        return $this->hasMany(
            MembershipRole::class,
            'membership_id',
        );
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'membership_roles',
            'membership_id',
            'role_id',
        );
    }
}
