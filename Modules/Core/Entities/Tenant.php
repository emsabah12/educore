<?php

namespace Modules\Core\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class Tenant extends Model
{
    use SoftDeletes;

    /**
     * Nama tabel yang terikat dengan model.
     *
     * @var string
     */
    protected $table = 'tenants';

    /**
     * Menandakan apakah primary key bersifat auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Tipe data dari primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Atribut yang dapat diisi secara massal (Mass Assignable).
     *
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
     * Casting tipe data atribut.
     *
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

    /**
     * Boot function untuk mengaitkan model event Eloquent.
     * Menjamin pembuatan UUID v7 sebelum data masuk ke database.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Tenant $tenant) {
            if (empty($tenant->id)) {
                // Menggunakan native UUID v7 dari Laravel Str helper
                $tenant->id = (string) Str::uuid7();
                
                Log::info('Tenant UUID v7 generated successfully.', [
                    'tenant_name' => $tenant->name,
                    'generated_id' => $tenant->id
                ]);
            }
        });
    }
}