<?php

declare(strict_types=1);

namespace Modules\Core\Person\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;

final class PersonLifecycleEventModel extends Model
{
    use HasUuidV7;

    protected $table = 'person_lifecycle_events';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'person_id',
        'type',
        'occurred_at',
        'actor_user_id',
        'reason',
    ];

    protected $casts = [
        'id' => 'string',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
