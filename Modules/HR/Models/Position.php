<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog jabatan HR milik tenant, mis. "Guru Matematika", "Kepala Tata
 * Usaha".
 *
 * PENTING (INV-HR-003, HR-002 §7): Position BUKAN authorization role.
 * Model ini tidak boleh diberi relasi ke role/permission Core RBAC.
 * Satu Employment boleh memegang lebih dari satu Position sekaligus
 * (OD-HR-DATA-003) melalui EmploymentPositionAssignment — yang akan kita
 * bangun di step berikutnya.
 */
final class Position extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'positions';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
