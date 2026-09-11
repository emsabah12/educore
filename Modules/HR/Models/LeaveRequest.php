<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu pengajuan Leave/Permit dan siklus hidupnya (HR-004 §7.7).
 *
 * "Phase 2C stores canonical UTC timestamps plus an explicit
 * request_timezone snapshot" — `starts_at`/`ends_at` SELALU UTC;
 * `request_timezone` murni jejak zona waktu asal input, BUKAN dipakai
 * untuk menafsirkan ulang kolom waktu.
 */
final class LeaveRequest extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_DRAFT = 'DRAFT';

    public const string STATUS_SUBMITTED = 'SUBMITTED';

    public const string STATUS_IN_REVIEW = 'IN_REVIEW';

    public const string STATUS_APPROVED = 'APPROVED';

    public const string STATUS_REJECTED = 'REJECTED';

    public const string STATUS_WITHDRAWN = 'WITHDRAWN';

    public const string STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'leave_requests';

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'employment_id',
        'leave_type_id',
        'approval_context_placement_id',
        'approval_policy_id',
        'submitted_by_membership_id',
        'status',
        'starts_at',
        'ends_at',
        'request_timezone',
        'requested_units',
        'unit',
        'reason',
        'submitted_at',
        'final_decided_at',
        'withdrawn_at',
        'cancelled_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'leave_type_id' => 'string',
        'approval_context_placement_id' => 'string',
        'approval_policy_id' => 'string',
        'submitted_by_membership_id' => 'string',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'requested_units' => 'decimal:2',
        'submitted_at' => 'immutable_datetime',
        'final_decided_at' => 'immutable_datetime',
        'withdrawn_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<LeaveApprovalPolicy, $this>
     */
    public function approvalPolicy(): BelongsTo
    {
        return $this->belongsTo(
            LeaveApprovalPolicy::class,
            'approval_policy_id',
        );
    }

    /**
     * @return HasMany<LeaveRequestEntitlementAllocation, $this>
     */
    public function entitlementAllocations(): HasMany
    {
        return $this->hasMany(
            LeaveRequestEntitlementAllocation::class,
            'leave_request_id',
        );
    }

    /**
     * @return HasMany<LeaveRequestApprovalStep, $this>
     */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(
            LeaveRequestApprovalStep::class,
            'leave_request_id',
        )->orderBy('step_order');
    }
}
