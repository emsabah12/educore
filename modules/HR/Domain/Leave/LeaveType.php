<?php

namespace Modules\HR\Domain\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Jenis cuti/izin milik sebuah yayasan (PRD-001 §13.2), mis. Cuti tahunan (berjatah 12 hari/tahun).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Leave\ManageLeaveTypes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $has_quota
 * @property int|null $annual_quota
 * @property int|null $max_days
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveType extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_leave_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'has_quota',
        'annual_quota',
        'max_days',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'has_quota' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_quota' => 'boolean',
            'annual_quota' => 'integer',
            'max_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
