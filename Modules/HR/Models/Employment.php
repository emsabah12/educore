<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu episode hubungan kerja seorang Employee (HR-002 §5.5).
 *
 * PENTING — model ini di step sekarang HANYA merepresentasikan struktur
 * data & relasi. Transaction algorithm untuk activate/end/cancel
 * (HR-002 §9) BELUM diimplementasikan di sini — itu akan menjadi
 * EmploymentLifecycleService pada step berikutnya, supaya business logic
 * bertransaksi tidak bercampur dengan definisi struktur model.
 */
final class Employment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_PLANNED = 'PLANNED';
    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_ENDED = 'ENDED';
    public const string STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'employments';

    protected $fillable = [
        'employee_id',
        'employment_type_id',
        'employment_classification_id',
        'status',
        'start_date',
        'end_date',
        'cancelled_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employee_id' => 'string',
        'employment_type_id' => 'string',
        'employment_classification_id' => 'string',
        'start_date' => 'date',
        'end_date' => 'date',
        'cancelled_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class,
            'employee_id',
        );
    }

    /**
     * @return BelongsTo<EmploymentType, $this>
     */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(
            EmploymentType::class,
            'employment_type_id',
        );
    }

    /**
     * @return BelongsTo<EmploymentClassification, $this>
     */
    public function employmentClassification(): BelongsTo
    {
        return $this->belongsTo(
            EmploymentClassification::class,
            'employment_classification_id',
        );
    }

    /**
     * Seluruh riwayat penempatan HR milik Employment ini, termasuk yang
     * sudah ditutup (effective_to terisi). Untuk placement yang sedang
     * berjalan, filter tambahan `whereNull('effective_to')` di pemanggil.
     *
     * @return HasMany<EmploymentPlacement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(
            EmploymentPlacement::class,
            'employment_id',
        );
    }

    /**
     * Seluruh riwayat penugasan jabatan milik Employment ini, termasuk
     * yang sudah ditutup. Filter tambahan `whereNull('effective_to')`
     * untuk yang sedang berjalan.
     *
     * @return HasMany<EmploymentPositionAssignment, $this>
     */
    public function positionAssignments(): HasMany
    {
        return $this->hasMany(
            EmploymentPositionAssignment::class,
            'employment_id',
        );
    }
}
