<?php

namespace Modules\Academic\Domain\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Satu sesi presensi sebuah kelas pada satu tanggal (PRD-002 §17). schedule_entry_id kosong =
 * presensi harian; terisi = per pertemuan (AC-4c).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Attendance\ManageAttendance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $class_id
 * @property CarbonImmutable $date
 * @property string|null $schedule_entry_id
 * @property string|null $recorded_by_membership_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendanceSession extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_attendance_sessions';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'class_id',
        'date',
        'schedule_entry_id',
        'recorded_by_membership_id',
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
