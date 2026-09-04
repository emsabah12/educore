<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu tahap seleksi berurutan milik sebuah RecruitmentVacancy
 * (HR-003 §7.3), mis. ADMIN_SCREEN, TEST, INTERVIEW, MICRO_TEACHING.
 *
 * "No special teacher-only workflow is hardcoded into the engine" —
 * model ini generik untuk semua jenis tahap; `code` cukup dibedakan
 * per baris, bukan lewat subclass/tabel terpisah per jenis tahap.
 */
final class RecruitmentVacancyStage extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'recruitment_vacancy_stages';

    protected $attributes = [
        'is_required' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'vacancy_id',
        'code',
        'name',
        'sequence',
        'is_required',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'vacancy_id' => 'string',
        'sequence' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
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
