<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Role;

/**
 * Relationship entity between a verified organizational placement and
 * a global Role catalog entry.
 *
 * Tenant, membership, organization and unit scope are intentionally derived
 * from OrganizationalAssignment instead of duplicated on this relationship.
 */
final class OrganizationalAssignmentRole extends Model
{
    protected $table = 'organizational_assignment_roles';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organizational_assignment_id',
        'role_id',
    ];

    /**
     * @return BelongsTo<OrganizationalAssignment, $this>
     */
    public function organizationalAssignment(): BelongsTo
    {
        return $this->belongsTo(
            OrganizationalAssignment::class,
            'organizational_assignment_id',
        );
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
        );
    }
}
