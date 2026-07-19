<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Core\Support\Traits\BelongsToTenant;
use Modules\User\Traits\HasContextualRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;
    use HasContextualRoles;

    /**
     * Menegaskan tipe kunci utama basis data untuk mencegah pemotongan nilai UUID oleh framework.
     */
    protected $keyType = 'string';

    /**
     * Menonaktifkan fitur auto-incrementing bawaan demi kompatibilitas UUID murni.
     */
    public $incrementing = false;

    /**
     * Atribut yang dapat diisi secara massal (Mass Assignment Protection).
     */
    protected $fillable = [
        // 'id',
        'name',
        'email',
        'password',
        // 'tenant_id',
        // 'is_superadmin', // <-- Tambahkan ke baris fillable
    ];

    /**
     * Atribut keamanan tersembunyi.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
        ];
    }

    /**
     * Booting lifecycle event hook milik Eloquent untuk menangani otomasi backend secara aman.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            // Otomatisasi pengisian UUID murni jika sisi pemanggil lupa menyertakan ID (sangat aman untuk produksi)
            if (empty($user->id)) {
                $user->id = (string) Str::uuid();
            }
        });
    }

    // Support Tenant Context
    use BelongsToTenant;
}
