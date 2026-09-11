<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu bucket entitlement untuk satu Employment + Leave Type + periode
 * (HR-004 §7.3).
 *
 * "There is deliberately no current_balance source-of-truth column" —
 * saldo dihitung dari SUM(units_delta) di LeaveBalanceLedger, TIDAK
 * PERNAH disimpan sebagai kolom mutable di sini.
 */
final class LeaveEntitlement extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_ACTIVE = 'ACTIVE';

    public const string STATUS_CLOSED = 'CLOSED';

    public const string STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'leave_entitlements';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'employment_id',
        'leave_type_id',
        'entitlement_policy_id',
        'period_start',
        'period_end',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'leave_type_id' => 'string',
        'entitlement_policy_id' => 'string',
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(
            LeaveType::class,
            'leave_type_id',
        );
    }

    /**
     * @return BelongsTo<LeaveEntitlementPolicy, $this>
     */
    public function entitlementPolicy(): BelongsTo
    {
        return $this->belongsTo(
            LeaveEntitlementPolicy::class,
            'entitlement_policy_id',
        );
    }
}
