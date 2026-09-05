<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu proses onboarding untuk satu Application yang berhasil
 * (HR-003 §7.12).
 *
 * `employee_id`/`employment_id` SENGAJA nullable — baru terisi lewat
 * hire conversion (Fase E, belum dibangun). Kasus ini bisa berjalan
 * sampai READY_FOR_ACTIVATION tanpa keduanya terisi sama sekali.
 */
final class OnboardingCase extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_NOT_STARTED = 'NOT_STARTED';
    public const string STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const string STATUS_READY_FOR_ACTIVATION = 'READY_FOR_ACTIVATION';
    public const string STATUS_COMPLETED = 'COMPLETED';
    public const string STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'onboarding_cases';

    protected $attributes = [
        'status' => self::STATUS_NOT_STARTED,
    ];

    protected $fillable = [
        'application_id',
        'template_id',
        'employee_id',
        'employment_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'application_id' => 'string',
        'template_id' => 'string',
        'employee_id' => 'string',
        'employment_id' => 'string',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentApplication::class,
            'application_id',
        );
    }

    /**
     * @return BelongsTo<OnboardingTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(
            OnboardingTemplate::class,
            'template_id',
        );
    }

    /**
     * @return HasMany<OnboardingTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(
            OnboardingTask::class,
            'onboarding_case_id',
        )->orderBy('sequence');
    }
}
