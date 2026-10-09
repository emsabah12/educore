<?php

namespace Modules\Core\Domain\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Modules\Core\Database\Factories\UserFactory;
use Modules\Core\Domain\Person\Person;

/**
 * Akun login global milik satu Person; tidak terikat ke tenant (PRD-000 §5).
 * Nama tampilan diambil dari Person, bukan disimpan di sini.
 *
 * @property string $id
 * @property string $person_id
 * @property string $email
 * @property string|null $username
 * @property string $password
 * @property UserStatus $status
 * @property bool $is_superadmin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Person $person
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * `status` dan `is_superadmin` sengaja TIDAK bisa diisi massal,
     * supaya tidak bisa diubah lewat form biasa.
     */
    protected $fillable = [
        'person_id',
        'email',
        'username',
        'password',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
        'is_superadmin' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_superadmin' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Email & username selalu disimpan huruf kecil tanpa spasi di tepi, sehingga
     * login tidak peka huruf besar/kecil. Database juga menolak huruf besar
     * (CHECK users_email_lowercase_check & users_username_format_check).
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = Str::lower(trim($user->email));

            if ($user->username !== null) {
                $user->username = Str::lower(trim($user->username));
            }
        });
    }
}
