<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Bukti eksplisit keputusan approve/reject terhadap sebuah
 * RecruitmentVacancy (HR-003 §7.2).
 *
 * "No decision is inferred only from generic audit logs" — baris di
 * tabel ini adalah SUMBER KEBENARAN untuk keputusan bisnis, bukan
 * turunan dari log audit yang sifatnya best-effort.
 */
final class RecruitmentVacancyDecision extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string DECISION_APPROVED = 'APPROVED';
    public const string DECISION_REJECTED = 'REJECTED';

    protected $table = 'recruitment_vacancy_decisions';

    protected $fillable = [
        'vacancy_id',
        'decision',
        'decided_by_membership_id',
        'reason',
        'decided_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'vacancy_id' => 'string',
        'decided_by_membership_id' => 'string',
        'decided_at' => 'immutable_datetime',
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
}
