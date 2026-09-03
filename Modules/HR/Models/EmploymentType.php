<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog jenis hubungan kerja milik tenant, mis. TETAP / KONTRAK / HONORER.
 *
 * Model ini SENGAJA tidak dibuat sebagai enum PHP/database karena
 * HR-002 §3 (OD-HR-DATA-002) mengunci keputusan bahwa Employment Type
 * adalah katalog tenant-scoped, bukan nilai tetap yang sama untuk semua
 * tenant.
 */
final class EmploymentType extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'employment_types';

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
