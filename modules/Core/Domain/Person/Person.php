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
 * Data pribadi minimal (tempat/tanggal lahir, HP, alamat) ditambahkan untuk modul HR
 * (PRD-001 §4.5, OD-HR-01). Semuanya opsional; NIK sengaja tidak disimpan.
 *
 * @property string $id
 * @property string $name
 * @property Gender|null $gender
 * @property string|null $birth_place
 * @property CarbonImmutable|null $birth_date
 * @property string|null $phone
 * @property string|null $address
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
        'birth_place',
        'birth_date',
        'phone',
        'address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'immutable_date',
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
