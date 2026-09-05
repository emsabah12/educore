<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Pelaksanaan konkret satu tahap seleksi untuk sebuah Application
 * (HR-003 §7.7).
 *
 * `vacancy_stage_id` adalah SNAPSHOT referensi ke konfigurasi Vacancy
 * pada saat submission — perubahan konfigurasi Vacancy belakangan
 * TIDAK PERNAH menulis ulang riwayat pelamar yang sudah ada (lihat
 * catatan di migration).
 */
final class RecruitmentApplicationStage extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_PENDING = 'PENDING';
    public const string STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const string STATUS_PASSED = 'PASSED';
    public const string STATUS_FAILED = 'FAILED';
    public const string STATUS_SKIPPED = 'SKIPPED';

    protected $table = 'recruitment_application_stages';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'application_id',
        'vacancy_stage_id',
        'status',
        'started_at',
        'completed_at',
        'completed_by_membership_id',
        'decision_note',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'application_id' => 'string',
        'vacancy_stage_id' => 'string',
        'completed_by_membership_id' => 'string',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<RecruitmentVacancyStage, $this>
     */
    public function vacancyStage(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentVacancyStage::class,
            'vacancy_stage_id',
        );
    }
}
