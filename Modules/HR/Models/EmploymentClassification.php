<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog klasifikasi institusional milik tenant, mis. GTY / GTT / PTY / PTT.
 *
 * Berbeda dari EmploymentType: klasifikasi ini bersifat opsional pada
 * Employment (lihat HR-002 §5.5) karena tidak semua kategori tenaga kerja
 * dijamin memakai skema klasifikasi seperti ini.
 */
final class EmploymentClassification extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    protected $table = 'employment_classifications';

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
