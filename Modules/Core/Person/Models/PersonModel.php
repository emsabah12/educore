<?php

declare(strict_types=1);

namespace Modules\Core\Person\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class PersonModel extends Model
{
    use HasUlids;

    protected $table = 'persons';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];
}
