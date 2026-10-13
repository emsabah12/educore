<?php

namespace Modules\Core\Application\Membership;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
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
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

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
                $membership = Membership::query()->create([
                    'person_id' => $personId,
                    'tenant_id' => $tenantId,
                ]);

                $this->recordGranted($membership, ['person_id' => $personId]);

                return $membership;
            }

            if (! $membership->isActive()) {
                $membership->status = MembershipStatus::Active;
                $membership->save();

                $this->recordGranted($membership, ['status' => [MembershipStatus::Inactive->value, MembershipStatus::Active->value]]);
            }

            return $membership;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordGranted(Membership $membership, array $changes): void
    {
        $this->audit->record(
            AuditAction::MEMBERSHIP_GRANTED,
            AuditLogger::SUBJECT_MEMBERSHIP,
            $membership->id,
            $changes,
            tenantId: $membership->tenant_id,
        );
    }
}
