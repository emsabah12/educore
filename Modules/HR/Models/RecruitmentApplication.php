<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu lamaran (Candidate x Vacancy) dan siklus hidupnya (HR-003 §7.6).
 *
 * INV-REC-002 (LOCKED) — "One Application per Candidate per Vacancy",
 * ditegakkan lewat UNIQUE(vacancy_id, candidate_id) di level database.
 *
 * State machine (§8.2):
 *     SUBMITTED -> IN_PROCESS -> {REJECTED, WITHDRAWN, HIRING_APPROVED}
 *     HIRING_APPROVED -> HIRED
 *
 * Business logic siklus hidup akan dibangun sebagai
 * RecruitmentApplicationLifecycleService di step berikutnya — model ini
 * murni struktur data & relasi.
 */
final class RecruitmentApplication extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_SUBMITTED = 'SUBMITTED';
    public const string STATUS_IN_PROCESS = 'IN_PROCESS';
    public const string STATUS_HIRING_APPROVED = 'HIRING_APPROVED';
    public const string STATUS_REJECTED = 'REJECTED';
    public const string STATUS_WITHDRAWN = 'WITHDRAWN';
    public const string STATUS_HIRED = 'HIRED';

    protected $table = 'recruitment_applications';

    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
    ];

    protected $fillable = [
        'vacancy_id',
        'candidate_id',
        'status',
        'submitted_at',
        'finalized_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'vacancy_id' => 'string',
        'candidate_id' => 'string',
        'submitted_at' => 'immutable_datetime',
        'finalized_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentVacancy, $this>
     */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentVacancy::class,
            'vacancy_id',
        );
    }

    /**
     * @return BelongsTo<RecruitmentCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentCandidate::class,
            'candidate_id',
        );
    }

    /**
     * @return HasMany<RecruitmentApplicationStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(
            RecruitmentApplicationStage::class,
            'application_id',
        );
    }

    /**
     * Riwayat keputusan hiring final (§7.9) — APPEND-ONLY.
     *
     * @return HasMany<RecruitmentHiringDecision, $this>
     */
    public function hiringDecisions(): HasMany
    {
        return $this->hasMany(
            RecruitmentHiringDecision::class,
            'application_id',
        )->orderByDesc('decided_at');
    }
}
