<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\HasUuidV7;

#[Fillable([
    'person_id',
    'email',
    'password',
])]
#[Hidden([
    'password',
    'remember_token',
])]
final class User extends Authenticatable
{
    use HasFactory;
    use HasUuidV7;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'person_id',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_id' => 'string',
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'status' => 'string',
            'is_superadmin' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Canonical human identity represented by this account.
     *
     * User is an optional account for exactly one Person. Human biodata
     * must be read from Person instead of being duplicated on users.
     *
     * @return BelongsTo<PersonModel, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(
            PersonModel::class,
            'person_id',
        );
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
