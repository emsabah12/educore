<?php

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicReportDetail extends Model
{
    use HasUuids;

    protected $table = 'academic_report_details';

    protected $fillable = [
        'tenant_id',
        'academic_report_card_id',
        'academic_subject_id',
        'final_score',
        'letter_grade',
        'predicate_notes'
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(AcademicReportCard::class, 'academic_report_card_id');
    }
}
