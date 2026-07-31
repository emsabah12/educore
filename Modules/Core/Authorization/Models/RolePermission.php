<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RolePermission extends Model
{
    protected $table = 'role_permissions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
        );
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(
            Permission::class,
            'permission_id',
        );
    }
}
