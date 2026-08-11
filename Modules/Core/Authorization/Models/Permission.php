<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;

final class Permission extends Model
{
    use HasUuidV7;

    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'module',
    ];

    protected $casts = [
        'id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(
            RolePermission::class,
            'permission_id',
        );
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions',
            'permission_id',
            'role_id',
        );
    }
}
