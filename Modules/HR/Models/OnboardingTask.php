<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Snapshot satu tugas checklist milik sebuah OnboardingCase
 * (HR-003 §7.13).
 *
 * "Changing a template never rewrites an existing onboarding case" —
 * seluruh kolom snapshot di sini DISALIN saat kasus dibuat, bukan
 * live-join ke OnboardingTemplateTask.
 */
final class OnboardingTask extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_PENDING = 'PENDING';
    public const string STATUS_COMPLETED = 'COMPLETED';
    public const string STATUS_WAIVED = 'WAIVED';

    protected $table = 'onboarding_tasks';

    protected $attributes = [
        'is_required' => true,
        'requires_evidence' => false,
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'onboarding_case_id',
        'template_task_id',
        'code',
        'title',
        'category',
        'sequence',
        'is_required',
        'requires_evidence',
        'status',
        'completed_by_membership_id',
        'completed_at',
        'completion_note',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'onboarding_case_id' => 'string',
        'template_task_id' => 'string',
        'sequence' => 'integer',
        'is_required' => 'boolean',
        'requires_evidence' => 'boolean',
        'completed_by_membership_id' => 'string',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<OnboardingCase, $this>
     */
    public function onboardingCase(): BelongsTo
    {
        return $this->belongsTo(
            OnboardingCase::class,
            'onboarding_case_id',
        );
    }
}
