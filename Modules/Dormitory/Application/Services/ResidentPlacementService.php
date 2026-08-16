<?php

declare(strict_types=1);

namespace Modules\Dormitory\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException;
use Modules\Dormitory\Application\Commands\CheckInResident;
use Modules\Dormitory\Application\Contracts\ResidentPlacementServiceInterface;
use Modules\Dormitory\Contracts\ResidentEligibilityCheckerInterface;
use Modules\Dormitory\Contracts\ResidentPlacementRepositoryInterface;
use Modules\Dormitory\Contracts\RoomRepositoryInterface;
use Modules\Dormitory\Domain\Enums\PlacementStatus;
use Modules\Dormitory\Domain\Enums\ResidentCategory;
use Modules\Dormitory\Domain\Exceptions\ResidentCheckInException;
use Modules\Dormitory\Domain\ValueObjects\PlacementResourceRequirements;
use Modules\Dormitory\Models\ResidentPlacement;

final readonly class ResidentPlacementService implements ResidentPlacementServiceInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private RoomRepositoryInterface $roomRepository,
        private ResidentPlacementRepositoryInterface $placementRepository,
        private ResidentEligibilityCheckerInterface $eligibilityChecker,
    ) {}

    public function checkIn(
        CheckInResident $command,
    ): ResidentPlacement {
        $tenantId = $this->resolveTenantId();
        $membershipId = $this->requireUuidV7(
            $command->membershipId,
            'membership_id',
        );
        $roomId = $this->requireUuidV7(
            $command->roomId,
            'room_id',
        );
        $bedId = $this->requireOptionalUuidV7(
            $command->bedId,
            'bed_id',
        );
        $lockerId = $this->requireOptionalUuidV7(
            $command->lockerId,
            'locker_id',
        );
        $residentCategory = ResidentCategory::tryFrom(
            trim($command->residentCategory),
        );

        if ($residentCategory === null) {
            throw ResidentCheckInException::invalidResidentCategory();
        }

        return DB::transaction(function () use (
            $tenantId,
            $membershipId,
            $roomId,
            $bedId,
            $lockerId,
            $residentCategory,
        ): ResidentPlacement {
            $room = $this->roomRepository
                ->findByIdAndTenantForUpdate(
                    $roomId,
                    $tenantId,
                );

            if ($room === null || ! $room->is_active) {
                throw ResidentCheckInException::roomUnavailable();
            }

            $building = $this->roomRepository->findBuildingForUpdate(
                (string) $room->building_id,
                $tenantId,
            );

            if ($building === null || ! $building->is_active) {
                throw ResidentCheckInException::roomUnavailable();
            }

            $this->eligibilityChecker->assertEligible(
                $tenantId,
                $membershipId,
                $residentCategory->value,
            );

            if (
                $this->placementRepository
                    ->findActiveForMembershipForUpdate(
                        $tenantId,
                        $membershipId,
                    ) !== null
            ) {
                throw ResidentCheckInException::activePlacementExists();
            }

            $placement = $this->placementRepository
                ->findPlannedForMembershipInRoomForUpdate(
                    $tenantId,
                    $membershipId,
                    $roomId,
                    $residentCategory->value,
                );

            if ($placement === null) {
                throw ResidentCheckInException::plannedPlacementNotFound();
            }

            $requirements = PlacementResourceRequirements::fromBasis(
                $room->capacity_basis,
            );

            $bed = $bedId === null
                ? null
                : $this->roomRepository->findBedForUpdate(
                    $roomId,
                    $bedId,
                    $tenantId,
                );

            if (
                $bedId !== null
                && (
                    $bed === null
                    || ! $bed->is_active
                    || ! $bed->is_usable
                )
            ) {
                throw ResidentCheckInException::bedUnavailable();
            }

            if (
                $bedId !== null
                && $this->placementRepository
                    ->findActiveForBedForUpdate(
                        $tenantId,
                        $bedId,
                    ) !== null
            ) {
                throw ResidentCheckInException::bedUnavailable();
            }

            $locker = $lockerId === null
                ? null
                : $this->roomRepository->findLockerForUpdate(
                    $roomId,
                    $lockerId,
                    $tenantId,
                );

            if (
                $lockerId !== null
                && (
                    $locker === null
                    || ! $locker->is_active
                    || ! $locker->is_usable
                )
            ) {
                throw ResidentCheckInException::lockerUnavailable();
            }

            if (
                $lockerId !== null
                && $this->placementRepository
                    ->findActiveForLockerForUpdate(
                        $tenantId,
                        $lockerId,
                    ) !== null
            ) {
                throw ResidentCheckInException::lockerUnavailable();
            }

            if (! $requirements->isSatisfiedBy(
                hasBed: $bed !== null,
                hasLocker: $locker !== null,
            )) {
                throw ResidentCheckInException::resourceRequirementsNotSatisfied();
            }

            $placement->bed_id = $bed?->getKey();
            $placement->locker_id = $locker?->getKey();
            $placement->status = PlacementStatus::ACTIVE;
            $placement->checked_in_at = now();

            return $this->placementRepository->save($placement);
        }, 3);
    }

    private function resolveTenantId(): string
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();

        if ($tenantId === null) {
            throw new TenantContextNotResolvedException;
        }

        return $this->requireUuidV7(
            $tenantId,
            'tenant_id',
        );
    }

    private function requireUuidV7(
        string $value,
        string $field,
    ): string {
        $value = trim($value);

        if (! UuidV7::validate($value)) {
            throw ResidentCheckInException::invalidIdentifier($field);
        }

        return $value;
    }

    private function requireOptionalUuidV7(
        ?string $value,
        string $field,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return $this->requireUuidV7(
            $value,
            $field,
        );
    }
}
