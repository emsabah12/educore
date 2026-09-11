<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu versi kebijakan approval Leave (HR-004 §7.5).
 *
 * "Once referenced by a submitted request, a policy version and its
 * steps are immutable. Changes create the next version." Model ini
 * murni struktur; penegakan immutability ada di service layer.
 */
final class LeaveApprovalPolicy extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string DECISION_MODE_SEQUENTIAL = 'SEQUENTIAL';

    public const string DECISION_MODE_AUTO = 'AUTO';

    protected $table = 'leave_approval_policies';

    protected $attributes = [
        'priority' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'policy_code',
        'version_no',
        'name',
        'leave_type_id',
        'organization_id',
        'organization_unit_id',
        'employment_type_id',
        'employment_classification_id',
        'decision_mode',
        'effective_from',
        'effective_to',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'version_no' => 'integer',
        'leave_type_id' => 'string',
        'organization_id' => 'string',
        'organization_unit_id' => 'string',
        'employment_type_id' => 'string',
        'employment_classification_id' => 'string',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<LeaveApprovalPolicyStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(
            LeaveApprovalPolicyStep::class,
            'approval_policy_id',
        )->orderBy('step_order');
    }

    /**
     * Skor spesifisitas scope, sama semangatnya dengan
     * LeaveEntitlementPolicy::scopeSpecificity() (§11 Approval Scope
     * Strategy): unit(2) > organization(1) > tenant-wide(0).
     */
    public function scopeSpecificity(): int
    {
        if ($this->organization_unit_id !== null) {
            return 2;
        }

        if ($this->organization_id !== null) {
            return 1;
        }

        return 0;
    }

    public function employmentFilterSpecificity(): int
    {
        return ($this->employment_type_id !== null ? 1 : 0)
            + ($this->employment_classification_id !== null ? 1 : 0);
    }

    public function leaveTypeSpecificity(): int
    {
        return $this->leave_type_id !== null ? 1 : 0;
    }
}
