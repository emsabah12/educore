<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

class AcademicReportDetail extends Model
{
    use HasUuids;
    use BelongsToTenant;

    protected $table = 'academic_report_details';

    protected $fillable = [
        'tenant_id',
        'academic_report_card_id',
        'academic_subject_id',
        'final_score',
        'letter_grade',
        'predicate_notes',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
    ];

    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(
            AcademicReportCard::class,
            'academic_report_card_id'
        );
    }
}
