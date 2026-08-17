<?php

declare(strict_types=1);

namespace Modules\User\Infrastructure\Queries;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\User\Application\DTO\WorkspaceSummary;
use Modules\User\Application\Queries\UserWorkspaceQueryInterface;

final class EloquentUserWorkspaceQuery implements
    UserWorkspaceQueryInterface
{
    /**
     * @return Collection<int, WorkspaceSummary>
     */
    public function findActiveForMembershipAndTenant(
        string $membershipId,
        string $tenantId,
    ): Collection {
        $membershipId = trim(
            $membershipId,
        );

        $tenantId = trim(
            $tenantId,
        );

        if (
            $membershipId === ''
            || $tenantId === ''
        ) {
            return collect();
        }

        return DB::table(
            'organizational_assignments as assignments',
        )
            ->join(
                'organizations as organizations',
                static function (
                    JoinClause $join,
                ): void {
                    $join
                        ->on(
                            'assignments.organization_id',
                            '=',
                            'organizations.id',
                        )
                        ->on(
                            'assignments.tenant_id',
                            '=',
                            'organizations.tenant_id',
                        );
                },
            )
            ->leftJoin(
                'organization_units as units',
                static function (
                    JoinClause $join,
                ): void {
                    $join
                        ->on(
                            'assignments.organization_unit_id',
                            '=',
                            'units.id',
                        )
                        ->on(
                            'assignments.organization_id',
                            '=',
                            'units.organization_id',
                        )
                        ->on(
                            'assignments.tenant_id',
                            '=',
                            'units.tenant_id',
                        );
                },
            )
            ->where(
                'assignments.membership_id',
                $membershipId,
            )
            ->where(
                'assignments.tenant_id',
                $tenantId,
            )
            ->where(
                'assignments.status',
                'ACTIVE',
            )
            ->where(
                'organizations.is_active',
                true,
            )
            ->whereNull(
                'organizations.deleted_at',
            )
            ->where(
                static function ($query): void {
                    $query
                        ->whereNull(
                            'assignments.organization_unit_id',
                        )
                        ->orWhere(
                            static function (
                                $unitQuery,
                            ): void {
                                $unitQuery
                                    ->where(
                                        'units.is_active',
                                        true,
                                    )
                                    ->whereNull(
                                        'units.deleted_at',
                                    );
                            },
                        );
                },
            )
            ->select([
                'assignments.id as assignment_id',
                'assignments.organization_id',
                'assignments.organization_unit_id',
                'organizations.name as organization_name',
                'units.name as organization_unit_name',
            ])
            ->orderBy(
                'organizations.name',
            )
            ->orderByRaw(
                'CASE
                    WHEN assignments.organization_unit_id IS NULL
                    THEN 0
                    ELSE 1
                END',
            )
            ->orderBy(
                'units.name',
            )
            ->get()
            ->map(
                static function (
                    object $row,
                ): WorkspaceSummary {
                    $organizationUnitId =
                        $row->organization_unit_id !== null
                        ? (string) $row->organization_unit_id
                        : null;

                    return new WorkspaceSummary(
                        type: $organizationUnitId === null
                            ? WorkspaceSummary::TYPE_ORGANIZATION
                            : WorkspaceSummary::TYPE_ORGANIZATION_UNIT,
                        organizationalAssignmentId: (string) $row->assignment_id,
                        organizationId: (string) $row->organization_id,
                        organizationUnitId: $organizationUnitId,
                        label: $organizationUnitId === null
                            ? (string) $row->organization_name
                            : (string) $row->organization_unit_name,
                    );
                },
            )
            ->values();
    }
}
