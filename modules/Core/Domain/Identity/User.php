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
 * @property string|null $email
 * @property string|null $username
 * @property string $password
 * @property UserStatus $status
 * @property bool $must_change_password
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
     * `status`, `is_superadmin`, dan `must_change_password` sengaja TIDAK bisa diisi massal,
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
        'must_change_password' => false,
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
            'must_change_password' => 'boolean',
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
     * Ganti kata sandi oleh pemiliknya sendiri: status "wajib ganti sandi" ikut selesai.
     */
    public function changeOwnPassword(string $password): void
    {
        $this->password = $password;
        $this->must_change_password = false;
        $this->save();
    }

    /**
     * Email & username selalu disimpan huruf kecil tanpa spasi di tepi, sehingga
     * login tidak peka huruf besar/kecil. Database juga menolak huruf besar
     * (CHECK users_email_lowercase_check & users_username_format_check).
     * Isian kosong disimpan sebagai null (email opsional, PRD-000 §4.7).
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = self::normalizeIdentifier($user->email);
            $user->username = self::normalizeIdentifier($user->username);
        });
    }

    private static function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::lower(trim($value));

        return $value === '' ? null : $value;
    }
}
