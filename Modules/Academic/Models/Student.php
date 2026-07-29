<?php

declare(strict_types=1);

namespace Modules\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Student extends Model
{
    use BelongsToTenant;

    protected $table = 'students';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'membership_id',
        'nisn',
        'kelas'
    ];

    /**
     * Relasi keanggotaan institusi (Inverse 1-to-1).
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id', 'id');
    }
}
