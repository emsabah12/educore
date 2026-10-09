<?php

namespace Modules\Core\Application\Membership;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Exceptions\MembershipException;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Mendaftarkan seorang Person sebagai anggota yayasan (PRD-000 §5).
 *
 * Aman dipanggil berulang: bila sudah menjadi anggota, membership yang ada
 * dipakai lagi (dan diaktifkan kembali bila sebelumnya nonaktif).
 */
final class GrantMembership
{
    /**
     * @throws MembershipException
     */
    public function handle(string $personId, string $tenantId): Membership
    {
        if (! Str::isUuid($personId) || ! Person::query()->whereKey($personId)->exists()) {
            throw MembershipException::personNotFound();
        }

        if (! Str::isUuid($tenantId) || ! Tenant::query()->whereKey($tenantId)->exists()) {
            throw MembershipException::tenantNotFound();
        }

        return DB::transaction(function () use ($personId, $tenantId): Membership {
            $membership = Membership::query()
                ->where('person_id', $personId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                return Membership::query()->create([
                    'person_id' => $personId,
                    'tenant_id' => $tenantId,
                ]);
            }

            if (! $membership->isActive()) {
                $membership->status = MembershipStatus::Active;
                $membership->save();
            }

            return $membership;
        });
    }
}
