<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Riwayat penempatan HR (HR-002 §5.6).
 *
 * PENTING: model ini BUKAN pengganti Core OrganizationalAssignment dan
 * tidak pernah menyimpan organization_id/organization_unit_id sendiri —
 * dia hanya mencatat "sejak kapan Employment ini menempel pada Core
 * Assignment tertentu". Core OrganizationalAssignment tetap menjadi
 * satu-satunya sumber kebenaran untuk otorisasi organisasi.
 *
 * Business logic (INV-HR-004, INV-HR-005, §9.2 Create Placement) akan
 * dibangun sebagai EmploymentPlacementService pada step berikutnya —
 * model ini murni struktur data & relasi.
 */
final class EmploymentPlacement extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'employment_placements';

    protected $fillable = [
        'employment_id',
        'organizational_assignment_id',
        'effective_from',
        'effective_to',
        'is_primary',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'organizational_assignment_id' => 'string',
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
     * @return BelongsTo<OrganizationalAssignment, $this>
     */
    public function organizationalAssignment(): BelongsTo
    {
        return $this->belongsTo(
            OrganizationalAssignment::class,
            'organizational_assignment_id',
        );
    }
}
