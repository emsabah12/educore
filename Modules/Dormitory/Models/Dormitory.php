<?php

declare(strict_types=1);

namespace Modules\Dormitory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Dormitory extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'dormitories';

    protected $fillable = [
        'organization_id',
        'organization_unit_id',
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'organization_id' => 'string',
        'organization_unit_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
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
