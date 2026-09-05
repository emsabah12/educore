<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Bukti eksplisit keputusan hiring final (approve/reject) atas sebuah
 * Application (HR-003 §7.9).
 *
 * "Only latest valid approved decision allows hire conversion. Decision
 * history is never overwritten." — model ini APPEND-ONLY dari sisi
 * aplikasi (tidak ada method update/delete yang disediakan sengaja).
 */
final class RecruitmentHiringDecision extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string DECISION_APPROVED = 'APPROVED';
    public const string DECISION_REJECTED = 'REJECTED';

    protected $table = 'recruitment_hiring_decisions';

    protected $fillable = [
        'application_id',
        'decision',
        'decided_by_membership_id',
        'reason',
        'decided_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'application_id' => 'string',
        'decided_by_membership_id' => 'string',
        'decided_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentApplication::class,
            'application_id',
        );
    }
}
