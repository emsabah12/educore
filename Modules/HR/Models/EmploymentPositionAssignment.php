<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Riwayat penugasan jabatan HR (HR-002 §5.7).
 *
 * `employment_placement_id` OPSIONAL — null berarti penugasan jabatan
 * tingkat-tenant (tidak terikat penempatan organisasi manapun). Business
 * logic (§9.3 Create Position Assignment) akan dibangun sebagai
 * EmploymentPositionAssignmentService pada step berikutnya — model ini
 * murni struktur data & relasi.
 */
final class EmploymentPositionAssignment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'employment_position_assignments';

    protected $fillable = [
        'employment_id',
        'position_id',
        'employment_placement_id',
        'effective_from',
        'effective_to',
        'is_primary',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'position_id' => 'string',
        'employment_placement_id' => 'string',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_primary' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Employment, $this>
     */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(
            Employment::class,
            'employment_id',
        );
    }

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
     * @return BelongsTo<EmploymentPlacement, $this>
     */
    public function employmentPlacement(): BelongsTo
    {
        return $this->belongsTo(
            EmploymentPlacement::class,
            'employment_placement_id',
        );
    }
}
