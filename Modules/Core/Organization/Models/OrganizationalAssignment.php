<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class OrganizationalAssignment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'organizational_assignments';

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'organization_id',
        'organization_unit_id',
        'status',
    ];

    protected $casts = [
        'tenant_id' => 'string',
        'membership_id' => 'string',
        'organization_id' => 'string',
        'organization_unit_id' => 'string',
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(
            Tenant::class,
            'tenant_id',
        );
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'membership_id',
        );
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
            'organization_id',
        );
    }

    /**
     * @return BelongsTo<OrganizationUnit, $this>
     */
    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(
            OrganizationUnit::class,
            'organization_unit_id',
        );
    }
}
