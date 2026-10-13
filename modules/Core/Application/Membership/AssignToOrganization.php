<?php

namespace Modules\Core\Application\Membership;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Tenancy\Exceptions\MembershipException;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Menugaskan seorang anggota di pohon lembaga (PRD-000 §4.4, §5).
 *
 *   $organizationId terisi, $jenjangFilter null   → penugasan struktural
 *   $organizationId terisi, $jenjangFilter terisi → fungsional di bawah node itu
 *   $organizationId null,   $jenjangFilter terisi → fungsional di seluruh Yayasan
 */
final class AssignToOrganization
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws MembershipException
     */
    public function handle(Membership $membership, ?string $organizationId, ?Jenjang $jenjangFilter = null): OrganizationalAssignment
    {
        if (! $membership->isActive()) {
            throw MembershipException::membershipInactive();
        }

        if ($organizationId === null && $jenjangFilter === null) {
            throw MembershipException::tenantLevelAssignmentNeedsJenjang();
        }

        if ($organizationId !== null) {
            $organization = Str::isUuid($organizationId)
                ? Organization::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereKey($organizationId)
                    ->first()
                : null;

            if ($organization === null) {
                throw MembershipException::organizationNotFound();
            }

            if (! $organization->isActive()) {
                throw MembershipException::organizationInactive();
            }
        }

        $isDuplicate = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('membership_id', $membership->id)
            ->where('organization_id', $organizationId)
            ->where('jenjang_filter', $jenjangFilter?->value)
            ->exists();

        if ($isDuplicate) {
            throw MembershipException::duplicateAssignment();
        }

        try {
            return DB::transaction(function () use ($membership, $organizationId, $jenjangFilter): OrganizationalAssignment {
                $assignment = OrganizationalAssignment::query()->create([
                    'tenant_id' => $membership->tenant_id,
                    'membership_id' => $membership->id,
                    'organization_id' => $organizationId,
                    'jenjang_filter' => $jenjangFilter,
                ]);

                $this->audit->record(
                    AuditAction::ASSIGNMENT_CREATED,
                    AuditLogger::SUBJECT_ASSIGNMENT,
                    $assignment->id,
                    [
                        'membership_id' => $membership->id,
                        'jenjang_filter' => $jenjangFilter?->value,
                    ],
                    organizationId: $organizationId,
                    tenantId: $membership->tenant_id,
                );

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Jaga-jaga bila dua permintaan yang sama masuk bersamaan.
            throw MembershipException::duplicateAssignment();
        }
    }
}
