<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;


#[Fillable([
    'name',
    'email',
    'password',
])]
#[Hidden([
    'password',
    'remember_token',
])]
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;


    /**
     * Nama tabel database.
     */
    protected $table = 'users';

    /**
     * User menggunakan UUID sebagai primary key.
     */
    protected $keyType = 'string';

    /**
     * UUID bukan auto increment.
     */
    public $incrementing = false;

    /**
     * Field yang boleh diisi melalui mass assignment.
     *
     * User adalah global identity.
     *
     * Tidak boleh memiliki:
     * - tenant_id
     * - organization_id
     * - membership_id
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Field sensitif yang tidak boleh diserialisasi.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casting.
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
     * Resolve the factory used to create User instances.
     *
     * Explicitly points the canonical Core Identity model
     * to the application's UserFactory.
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }

    /**
     * Model boot lifecycle.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->ensureUuid();
        });
    }

    /**
     * Ensure the user has a UUID v7 primary key before persistence.
     */
    private function ensureUuid(): void
    {
        if ($this->getKey() === null) {
            $this->setAttribute(
                $this->getKeyName(),
                (string) Str::uuid7()
            );
        }
    }
}
