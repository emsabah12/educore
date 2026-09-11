<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Instance runtime satu langkah approval untuk satu Leave Request,
 * disalin dari LeaveApprovalPolicyStep pada saat submit (HR-004 §7.9).
 *
 * "Only the current actionable step can be approved/rejected." —
 * INV-HR-LEAVE-006, ditegakkan di service layer.
 */
final class LeaveRequestApprovalStep extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_APPROVED = 'APPROVED';

    public const string STATUS_REJECTED = 'REJECTED';

    public const string STATUS_SKIPPED = 'SKIPPED';

    protected $table = 'leave_request_approval_steps';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'leave_request_id',
        'policy_step_id',
        'step_order',
        'required_permission',
        'scope_strategy',
        'independent_approver',
        'status',
        'decided_by_membership_id',
        'decision_note',
        'decided_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'leave_request_id' => 'string',
        'policy_step_id' => 'string',
        'step_order' => 'integer',
        'independent_approver' => 'boolean',
        'decided_by_membership_id' => 'string',
        'decided_at' => 'immutable_datetime',
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
}
