<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Support\Uuid\HasUuidV7;

final class SubscriptionFeature extends Model
{
    use HasUuidV7;

    protected $table = 'subscription_features';

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    protected $casts = [
        'id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            SubscriptionPlan::class,
            'plan_features',
            'feature_id',
            'plan_id',
        );
    }
}
