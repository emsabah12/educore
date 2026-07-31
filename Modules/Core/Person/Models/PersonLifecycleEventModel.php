<?php

declare(strict_types=1);

namespace Modules\Core\Person\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PersonLifecycleEventModel extends Model
{
    use HasUlids;

    protected $table = 'person_lifecycle_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'person_id',
        'type',
        'occurred_at',
        'actor_id',
        'reason',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];
}
