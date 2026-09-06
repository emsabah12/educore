<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

use Modules\Core\Authorization\Models\Membership;

/**
 * HR-003 §10 — "Current Core repository only exposes active Membership
 * lookup by ID; it does not provide Person+Tenant ensure/reactivation
 * lifecycle."
 */
interface MembershipLifecycleServiceInterface
{
    /**
     * §10 langkah 1-5:
     *   1. lock/select existing (person_id, tenant_id) Membership;
     *   2. kalau ACTIVE -> kembalikan;
     *   3. kalau INACTIVE -> reaktivasi baris yang sama + audit;
     *   4. kalau tidak ada -> buat Membership ACTIVE baru;
     *   5. TIDAK PERNAH membuat Membership duplikat.
     *
     * UNIQUE(person_id, tenant_id) di database tetap jadi penjaga
     * konkurensi terakhir (§10 langkah 6).
     */
    public function ensureActiveForPersonAndTenant(
        string $personId,
        string $tenantId,
    ): Membership;
}
