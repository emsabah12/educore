<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Paket aktif SAAT INI untuk satu tenant — bukan tabel riwayat (lihat
 * catatan di migrasi). TIDAK memakai trait `BelongsToTenant` karena
 * baris ini dibaca/ditulis superadmin yang beroperasi TANPA konteks
 * tenant aktif (sama seperti model `Tenant` itu sendiri).
 */
final class TenantSubscription extends Model
{
    use HasUuidV7;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    protected $table = 'tenant_subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'activated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'plan_id' => 'string',
        'trial_ends_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
