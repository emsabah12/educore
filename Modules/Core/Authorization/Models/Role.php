<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Role extends Model
{
    protected $table = 'roles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function membershipRoles(): HasMany
    {
        return $this->hasMany(
            MembershipRole::class,
            'role_id',
        );
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id',
        );
    }
}
