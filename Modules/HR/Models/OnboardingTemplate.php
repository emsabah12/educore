<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Template checklist onboarding yang bisa dipakai ulang dalam Tenant
 * (HR-003 §7.10).
 *
 * "No Position/RBAC permission is embedded into the template" — model
 * ini murni struktur data, otorisasi ditegakkan di HTTP layer.
 */
final class OnboardingTemplate extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'onboarding_templates';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<OnboardingTemplateTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(
            OnboardingTemplateTask::class,
            'template_id',
        )->orderBy('sequence');
    }
}
