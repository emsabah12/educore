<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\User; // Teacher Model

final class StudentGrade extends Model
{
    use HasUuids;

    protected $table = 'student_grades';

    protected $fillable = [
        'tenant_id',
        'assessment_setting_id',
        'santri_id',
        'teacher_id',
        'score',
        'notes'
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $model) => $model->id = $model->id ?? (string) Str::uuid());
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(AssessmentSetting::class, 'assessment_setting_id');
    }
}
