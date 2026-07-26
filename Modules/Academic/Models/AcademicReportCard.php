<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

class AcademicReportCard extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'academic_report_cards';

    protected $fillable = [
        'tenant_id',
        'academic_period_id',
        'student_id',
        'academic_class_id',
        'attendance_sick',
        'attendance_permission',
        'attendance_absent',
        'teacher_notes',
        'status',
        'locked_by',
        'locked_at',
    ];

    protected $casts = [
        'attendance_sick' => 'integer',
        'attendance_permission' => 'integer',
        'attendance_absent' => 'integer',
        'locked_at' => 'datetime',
    ];



    public function details(): HasMany
    {
        return $this->hasMany(
            AcademicReportDetail::class,
            'academic_report_card_id'
        );
    }
}
