<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu kebutuhan/lowongan rekrutmen yang sudah disetujui dalam Tenant
 * (HR-003 §7.1).
 *
 * PENTING (INV-REC-010 — "Vacancy placement is intent only"):
 * `organization_id`/`organization_unit_id` di sini murni TARGET niat
 * rekrutmen, BUKAN pernyataan penempatan Employee yang canonical.
 * Penempatan yang sah tetap hanya lewat EmploymentPlacement (RM-HR-01).
 *
 * Business logic siklus hidup (submit/approve/reject/open/close/cancel,
 * §8.1) akan dibangun sebagai RecruitmentVacancyLifecycleService di
 * step berikutnya — model ini murni struktur data & relasi.
 */
final class RecruitmentVacancy extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_DRAFT = 'DRAFT';
    public const string STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const string STATUS_APPROVED = 'APPROVED';
    public const string STATUS_OPEN = 'OPEN';
    public const string STATUS_CLOSED = 'CLOSED';
    public const string STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'recruitment_vacancies';

    /**
     * Default level-aplikasi ini SENGAJA mencerminkan persis default
     * level-database (`status` DEFAULT 'DRAFT' di migration). Eloquent
     * tidak otomatis membaca kembali default database ke objek PHP
     * setelah create() kecuali di-refresh() — tanpa baris ini,
     * `$vacancy->status` akan tampak `null` di memori walau di database
     * sudah benar tersimpan 'DRAFT'.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'code',
        'title',
        'position_id',
        'organization_id',
        'organization_unit_id',
        'requested_headcount',
        'description',
        'status',
        'open_at',
        'close_at',
        'created_by_membership_id',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'position_id' => 'string',
        'organization_id' => 'string',
        'organization_unit_id' => 'string',
        'requested_headcount' => 'integer',
        'open_at' => 'immutable_datetime',
        'close_at' => 'immutable_datetime',
        'created_by_membership_id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(
            Position::class,
            'position_id',
        );
    }

    /**
     * Seluruh riwayat keputusan approve/reject Vacancy ini.
     *
     * @return HasMany<RecruitmentVacancyDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(
            RecruitmentVacancyDecision::class,
            'vacancy_id',
        );
    }

    /**
     * Tahapan seleksi berurutan yang dikonfigurasi untuk Vacancy ini.
     *
     * @return HasMany<RecruitmentVacancyStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(
            RecruitmentVacancyStage::class,
            'vacancy_id',
        )->orderBy('sequence');
    }
}
