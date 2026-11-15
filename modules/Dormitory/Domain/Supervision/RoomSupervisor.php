<?php

namespace Modules\Dormitory\Domain\Supervision;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Dormitory\Domain\Facility\Room;

/**
 * Penugasan wali kamar (PRD-008 §6, §17): pegawai yang mengawasi kamar. Aktif selama end_on kosong. Jangan
 * membuat/mengubah langsung; pakai Modules\Dormitory\Application\Supervision\ManageRoomSupervisors.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $room_id
 * @property string $membership_id
 * @property CarbonImmutable $start_on
 * @property CarbonImmutable|null $end_on
 * @property string|null $note
 * @property string|null $created_by
 * @property string|null $ended_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Room $room
 */
class RoomSupervisor extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_room_supervisors';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'dormitory_id',
        'room_id',
        'membership_id',
        'start_on',
        'end_on',
        'note',
        'created_by',
        'ended_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_on' => 'immutable_date',
            'end_on' => 'immutable_date',
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

    public function isActive(): bool
    {
        return $this->end_on === null;
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
