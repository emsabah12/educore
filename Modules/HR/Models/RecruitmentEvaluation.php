<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Bukti evaluasi eksplisit dari satu evaluator untuk satu tahap seleksi
 * (HR-003 §7.8). Desain sengaja sederhana (skor tunggal + rekomendasi)
 * — bukan form builder generik.
 */
final class RecruitmentEvaluation extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string RECOMMENDATION_PASS = 'PASS';
    public const string RECOMMENDATION_FAIL = 'FAIL';
    public const string RECOMMENDATION_HOLD = 'HOLD';

    protected $table = 'recruitment_evaluations';

    protected $fillable = [
        'application_stage_id',
        'evaluator_membership_id',
        'score',
        'max_score',
        'recommendation',
        'remarks',
        'submitted_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'application_stage_id' => 'string',
        'evaluator_membership_id' => 'string',
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'submitted_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentApplicationStage, $this>
     */
    public function applicationStage(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentApplicationStage::class,
            'application_stage_id',
        );
    }
}