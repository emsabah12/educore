<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Employee extends Model
{
    // Pelindung kueri otomatis & injeksi tenant_id otomatis saat creating
    use BelongsToTenant;

    protected $table = 'employees';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'membership_id',
        'nip',
        'jabatan'
    ];

    /**
     * Relasi keanggotaan institusi (Inverse 1-to-1).
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id', 'id');
    }
}
