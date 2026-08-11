<?php

declare(strict_types=1);

namespace Modules\Core\Person\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Person\Database\Factories\PersonFactory;
use Modules\Core\Support\Uuid\HasUuidV7;

final class PersonModel extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $table = 'persons';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'given_name',
        'middle_name',
        'family_name',
        'birth_date',
        'birth_place_name',
        'birth_country_code',
        'legal_sex',
        'civil_status',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'birth_date' => 'immutable_date',
        'status' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

}
