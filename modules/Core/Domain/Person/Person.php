<?php

namespace Modules\Core\Domain\Person;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Core\Database\Factories\PersonFactory;
use Modules\Core\Domain\Identity\User;

/**
 * Identitas manusia global (PRD-000 §5). Satu-satunya pemilik nama seseorang.
 *
 * @property string $id
 * @property string $name
 * @property Gender|null $gender
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'persons';

    protected $fillable = [
        'name',
        'gender',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

    /**
     * Akun login milik Person ini (opsional; tidak semua orang punya akun).
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
