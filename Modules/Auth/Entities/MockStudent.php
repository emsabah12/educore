<?php

declare(strict_types=1);

namespace Modules\Auth\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

final class MockStudent extends Model
{
    // Mengaktifkan Pelindung Isolasi Data Multi-Tenant otomatis
    use BelongsToTenant;

    /**
     * Nama tabel fisiknya di database PostgreSQL.
     *
     * @var string
     */
    protected $table = 'mock_students';

    /**
     * Properti yang diizinkan untuk pengisian massal (Mass Assignment).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'nisn',
        'status'
    ];

    /**
     * Menonaktifkan Auto-Increment karena menggunakan sistem identitas terdistribusi UUID.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Tipe data primary key dikunci menjadi string untuk UUID.
     *
     * @var string
     */
    protected $keyType = 'string';
}
