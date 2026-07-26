<?php

declare(strict_types=1);

namespace Modules\Auth\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class Guardian extends Model
{
    use BelongsToTenant;

    protected $table = 'walisantris';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'membership_id',
        'no_hp',
        'alamat_domisili'
    ];

    /**
     * Relasi keanggotaan institusi (Inverse 1-to-1).
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id', 'id');
    }
}
