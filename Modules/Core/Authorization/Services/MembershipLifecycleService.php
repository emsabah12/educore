<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\MembershipLifecycleServiceInterface;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final readonly class MembershipLifecycleService implements MembershipLifecycleServiceInterface
{
    public function __construct(
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    public function ensureActiveForPersonAndTenant(
        string $personId,
        string $tenantId,
    ): Membership {
        return DB::transaction(function () use ($personId, $tenantId): Membership {
            /** @var Membership|null $membership */
            $membership = Membership::query()
                ->where('person_id', $personId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($membership !== null && $membership->status === 'ACTIVE') {
                return $membership;
            }

            if ($membership !== null) {
                // Reaktivasi baris yang SAMA — bukan membuat baris baru
                // (§10 langkah 3 & 5).
                $membership->status = 'ACTIVE';
                $membership->save();

                $this->auditSafely(
                    eventType: 'membership.reactivated',
                    description: 'Reactivated Membership for hiring conversion.',
                    tenantId: $tenantId,
                    metadata: [
                        'membership_id' => $membership->id,
                        'person_id' => $personId,
                    ],
                );

                return $membership->refresh();
            }

            $membership = new Membership;
            $membership->id = UuidV7::generate();
            $membership->person_id = $personId;
            $membership->tenant_id = $tenantId;
            $membership->status = 'ACTIVE';
            $membership->save();

            return $membership;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function auditSafely(
        string $eventType,
        string $description,
        string $tenantId,
        array $metadata,
    ): void {
        try {
            $this->auditTrail->log(
                eventType: $eventType,
                description: $description,
                tenantId: $tenantId,
                actorUserId: null,
                metadata: $metadata,
            );
        } catch (Throwable $auditException) {
            report($auditException);
        }
    }
}
