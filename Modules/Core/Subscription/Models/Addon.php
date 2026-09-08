<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;

final class Addon extends Model
{
    use HasUuidV7;

    protected $table = 'addons';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'feature_id',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'feature_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(SubscriptionFeature::class, 'feature_id');
    }
}
