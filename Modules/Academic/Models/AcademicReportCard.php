<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Modules\Academic\Models\AcademicReportDetail;

class AcademicReportCard extends Model
{
    use HasUuids;

    protected $table = 'academic_report_cards';

    protected $fillable = [
        'tenant_id',
        'academic_period_id',
        'santri_id',
        'academic_class_id',
        'attendance_sick',
        'attendance_permission',
        'attendance_absent',
        'teacher_notes',
        'status',
        'locked_by',
        'locked_at'
    ];

    protected $casts = [
        'attendance_sick' => 'integer',
        'attendance_permission' => 'integer',
        'attendance_absent' => 'integer',
        'locked_at' => 'datetime',
    ];

    // Global Scope untuk Tenant Isolation (Keamanan data)
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    public function details(): HasMany
    {
        return $this->hasMany(AcademicReportDetail . php, 'academic_report_card_id');
    }
}
