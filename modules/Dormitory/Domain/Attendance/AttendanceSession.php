<?php

namespace Modules\Dormitory\Domain\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Sesi absen asrama: kamar + tanggal + jenis (PRD-008 §22.2). Jangan membuat/mengubah langsung; pakai
 * Modules\Dormitory\Application\Attendance\ManageDormitoryAttendance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $room_id
 * @property string $kind_id
 * @property CarbonImmutable $date
 * @property string|null $recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendanceSession extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_attendance_sessions';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'dormitory_id',
        'room_id',
        'kind_id',
        'date',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function owningTenantId(): string
    {
        return $this->tenant_id;
    }

    public function owningOrganizationId(): string
    {
        return $this->organization_id;
    }
}
