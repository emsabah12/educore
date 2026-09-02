<?php

declare(strict_types=1);

namespace Modules\Core\Person\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;

final class PersonIdentifierModel extends Model
{
    use HasUuidV7;

    protected $table = 'person_identifiers';

    protected $keyType = 'string';

    public $incrementing = false;

    /*
     * encrypted_value dan value_fingerprint SENGAJA tidak fillable
     * lewat mass-assignment biasa — keduanya hanya boleh ditulis lewat
     * EloquentPersonIdentifierRepository yang memaksa jalur cipher,
     * supaya tidak ada jalur lain yang bisa menyimpan raw value.
     */
    protected $fillable = [
        'id',
        'person_id',
        'type',
        'issuing_country_code',
        'issuer',
        'issued_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'issued_at' => 'immutable_date',
        'expires_at' => 'immutable_date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
