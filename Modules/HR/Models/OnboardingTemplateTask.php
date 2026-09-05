<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu definisi tugas checklist di dalam sebuah OnboardingTemplate
 * (HR-003 §7.11).
 *
 * "A DOCUMENT task is a business requirement/checkpoint, not a new
 * storage subsystem" — tidak ada penyimpanan file di sini, murni
 * definisi tugas administratif.
 */
final class OnboardingTemplateTask extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string CATEGORY_DOCUMENT = 'DOCUMENT';
    public const string CATEGORY_ORIENTATION = 'ORIENTATION';
    public const string CATEGORY_CONTRACT = 'CONTRACT';
    public const string CATEGORY_ADMIN = 'ADMIN';

    protected $table = 'onboarding_template_tasks';

    protected $attributes = [
        'is_required' => true,
        'requires_evidence' => false,
    ];

    protected $fillable = [
        'template_id',
        'code',
        'title',
        'category',
        'sequence',
        'is_required',
        'requires_evidence',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'template_id' => 'string',
        'sequence' => 'integer',
        'is_required' => 'boolean',
        'requires_evidence' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

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
}
