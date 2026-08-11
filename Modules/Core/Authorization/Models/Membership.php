<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\HasUuidV7;

final class Membership extends Model
{
    use HasUuidV7;

    protected $table = 'memberships';

    protected $fillable = [
        'person_id',
        'tenant_id',
        'status',
    ];

    protected $casts = [
        'person_id' => 'string',
        'tenant_id' => 'string',
        'status' => 'string',
    ];

    /**
     * Canonical human participant that owns this tenant membership.
     *
     * Membership belongs to Person, never directly to User. A Person may
     * participate in a tenant without having a digital login account.
     *
     * @return BelongsTo<PersonModel, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(
            PersonModel::class,
            'person_id',
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
