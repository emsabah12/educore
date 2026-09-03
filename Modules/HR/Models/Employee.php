<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany; // ditambahkan ke daftar use di atas
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Employee extends Model
{
    use BelongsToTenant;
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'employees';

    protected $fillable = [
        'membership_id',
        'nip',
        'jabatan',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'membership_id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'membership_id',
        );
    }

    /**
     * Seluruh episode Employment milik Employee ini, termasuk yang sudah
     * ENDED/CANCELLED. Untuk mengambil Employment yang sedang berjalan,
     * filter tambahan `where('status', Employment::STATUS_ACTIVE)` di
     * pemanggil (HR-002 INV-HR-002: maksimal satu yang ACTIVE).
     *
     * @return HasMany<Employment, $this>
     */
    public function employments(): HasMany
    {
        return $this->hasMany(
            Employment::class,
            'employee_id',
        );
    }
}
