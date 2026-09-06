<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Alokasi antara satu Leave Request balance-backed dan satu entitlement
 * bucket (HR-004 §7.8). Satu request bisa punya beberapa baris alokasi
 * kalau intervalnya melintasi lebih dari satu periode entitlement.
 */
final class LeaveRequestEntitlementAllocation extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'leave_request_entitlement_allocations';

    protected $fillable = [
        'leave_request_id',
        'entitlement_id',
        'allocated_units',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'leave_request_id' => 'string',
        'entitlement_id' => 'string',
        'allocated_units' => 'decimal:2',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(
            LeaveRequest::class,
            'leave_request_id',
        );
    }

    /**
     * @return BelongsTo<LeaveEntitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(
            LeaveEntitlement::class,
            'entitlement_id',
        );
    }
}
