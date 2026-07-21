<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

final class AssessmentSetting extends Model
{
    use HasUuids;

    protected $table = 'assessment_settings';

    protected $fillable = [
        'tenant_id',
        'academic_period_id',
        'academic_subject_id',
        'component_name',
        'weight'
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $model) => $model->id = $model->id ?? (string) Str::uuid());
    }

    public function grades(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentGrade::class);
    }
}
