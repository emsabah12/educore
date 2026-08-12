<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class StudentGrade extends Model
{
    use HasUuidV7;
    use BelongsToTenant;

    protected $table = 'student_grades';

    protected $fillable = [

        'assessment_setting_id',
        'student_id',
        'teacher_id',
        'score',
        'notes',
    ];

    protected $casts = [
        'score' => 'decimal:2',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentSetting::class,
            'assessment_setting_id'
        );
    }
}
