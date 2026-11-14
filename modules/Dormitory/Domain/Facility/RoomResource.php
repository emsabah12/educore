<?php

namespace Modules\Dormitory\Domain\Facility;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Bed atau loker di sebuah kamar (PRD-008 §4.4–§4.5). Satu pintu/kompartemen lemari = satu loker.
 *
 * - `status` Tersedia/Tidak tersedia (+ alasan) menentukan apakah ikut dihitung dalam kapasitas.
 * - `is_active` = masih termasuk inventaris kamar; yang nonaktif disembunyikan dari pilihan, riwayatnya tetap.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $room_id
 * @property string $code
 * @property ResourceStatus $status
 * @property UnavailableReason|null $unavailable_reason
 * @property string|null $unavailable_note
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Room $room
 */
abstract class RoomResource extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'organization_id', 'room_id', 'code', 'status', 'unavailable_reason', 'unavailable_note', 'is_active'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'TERSEDIA',
        'is_active' => true,
    ];

    abstract public function kind(): ResourceKind;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResourceStatus::class,
            'unavailable_reason' => UnavailableReason::class,
            'is_active' => 'boolean',
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

    /**
     * Ikut dihitung dalam kapasitas kamar: masih di inventaris dan berstatus tersedia.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->status === ResourceStatus::Available;
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
