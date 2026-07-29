<?php

declare(strict_types=1);

namespace Modules\Academic\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class AssessmentSetting extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'assessment_settings';

    protected $fillable = [

        'academic_period_id',
        'academic_subject_id',
        'component_name',
        'weight',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
    ];

    public function grades(): HasMany
    {
        return $this->hasMany(
            StudentGrade::class,
            'assessment_setting_id'
        );
    }
}
