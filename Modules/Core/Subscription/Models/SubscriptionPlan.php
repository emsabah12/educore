<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Support\Uuid\HasUuidV7;


final class SubscriptionPlan extends Model
{
    use HasUuidV7;

    protected $table = 'subscription_plans';

    protected $attributes = [
        'grace_period_days' => 30,
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'grace_period_days',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'grace_period_days' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            SubscriptionFeature::class,
            'plan_features',
            'plan_id',
            'feature_id',
        );
    }
}
