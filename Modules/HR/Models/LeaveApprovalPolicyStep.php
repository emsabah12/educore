<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu langkah approval milik satu versi kebijakan (HR-004 §7.6).
 *
 * "required_permission is a stable capability code, not a Position
 * name." Model ini TIDAK PUNYA updated_at (const UPDATED_AT = null) —
 * begitu policy version-nya dirujuk Application yang sudah submit,
 * langkah-langkah ini immutable.
 */
final class LeaveApprovalPolicyStep extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string SCOPE_REQUEST_PLACEMENT = 'REQUEST_PLACEMENT';
    public const string SCOPE_ORGANIZATION = 'ORGANIZATION';
    public const string SCOPE_TENANT = 'TENANT';

    public const ?string UPDATED_AT = null;

    protected $table = 'leave_approval_policy_steps';

    protected $attributes = [
        'independent_approver' => true,
    ];

    protected $fillable = [
        'approval_policy_id',
        'step_order',
        'required_permission',
        'scope_strategy',
        'independent_approver',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'approval_policy_id' => 'string',
        'step_order' => 'integer',
        'independent_approver' => 'boolean',
        'created_at' => 'immutable_datetime',
    ];

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
}
