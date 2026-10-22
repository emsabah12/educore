<?php

namespace Modules\HR\Domain\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Satu pengajuan cuti pegawai (PRD-001 §13.3). Lembaga = penempatan utama saat diajukan.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Leave\ManageLeaveRequests.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property string $organization_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property int $days
 * @property array<int, int> $days_by_year tahun => hari kerja
 * @property string $reason
 * @property LeaveStatus $status
 * @property int $approval_steps
 * @property int|null $current_step
 * @property string|null $recorded_by_membership_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveRequest extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_leave_requests';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'leave_type_id',
        'organization_id',
        'start_date',
        'end_date',
        'days',
        'days_by_year',
        'reason',
        'status',
        'approval_steps',
        'current_step',
        'recorded_by_membership_id',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'days' => 'integer',
            'days_by_year' => 'array',
            'status' => LeaveStatus::class,
            'approval_steps' => 'integer',
            'current_step' => 'integer',
            'decided_at' => 'immutable_datetime',
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
