<?php

namespace Modules\Core\Domain\Authorization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Kumpulan permission di katalog global (PRD-000 §5).
 *
 * Role yang sama bisa dipasang tenant-wide (membership_roles) maupun
 * di sebuah penugasan (organizational_assignment_roles).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }
}
