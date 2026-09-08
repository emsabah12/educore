<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;

final class Role extends Model
{
    use HasUuidV7;

    protected $table = 'roles';

    protected $fillable = [
        'tenant_id',
        'name',
        'display_name',
        'description',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * `NULL` = role sistem/global. Terisi = role kustom milik SATU
     * tenant — lihat `TenantRoleService` untuk logika efektivitasnya.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

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
