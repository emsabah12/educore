<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Support\Uuid\HasUuidV7;

class Tenant extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'tenants';

    /**
     * Tetap eksplisit agar pembacaan property langsung juga konsisten
     * dengan getIncrementing() pada HasUuidV7.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'name',
        'domain',
        'subdomain',
        'is_active',
        'settings',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'is_active' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
