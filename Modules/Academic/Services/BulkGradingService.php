<?php

declare(strict_types=1);

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BulkGradingService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employeeRepository,
    ) {}

    /**
     * @param array{
     *     assessment_setting_id:string,
     *     grades:array<int,array{student_id:string,score:int|float|string,notes?:string|null}>
     * } $payload
     * @return array{employee_id:string,processed:int}
     */
    public function store(
        string $tenantId,
        string $membershipId,
        array $payload,
    ): array {
        return DB::transaction(function () use (
            $tenantId,
            $membershipId,
            $payload,
        ): array {
            $employee = $this->employeeRepository
                ->findByMembershipForTenant(
                    $membershipId,
                    $tenantId,
                );

            if ($employee === null) {
                throw new AccessDeniedHttpException(
                    'Authenticated membership does not have an Employee grading actor.',
                );
            }

            $employeeId = (string) ($employee['employee_id'] ?? '');

            if (! UuidV7::validate($employeeId)) {
                throw new AccessDeniedHttpException(
                    'Authenticated membership does not have an Employee grading actor.',
                );
            }

            $assessmentSettingId = $payload['assessment_setting_id'];

            $assessmentExists = DB::table('assessment_settings')
                ->where('id', $assessmentSettingId)
                ->where('tenant_id', $tenantId)
                ->exists();

            if (! $assessmentExists) {
                throw new NotFoundHttpException(
                    'Assessment setting was not found in the active tenant.',
                );
            }

            $studentIds = collect($payload['grades'])
                ->pluck('student_id')
                ->values();

            $validStudentCount = DB::table('students')
                ->join(
                    'memberships',
                    'students.membership_id',
                    '=',
                    'memberships.id',
                )
                ->where('students.tenant_id', $tenantId)
                ->where('memberships.tenant_id', $tenantId)
                ->whereNull('students.deleted_at')
                ->whereIn('students.id', $studentIds)
                ->distinct()
                ->count('students.id');

            if ($validStudentCount !== $studentIds->count()) {
                throw new NotFoundHttpException(
                    'One or more students were not found in the active tenant.',
                );
            }

            $now = now();

            $rows = collect($payload['grades'])
                ->map(
                    static fn (array $grade): array => [
                        'id' => UuidV7::generate(),
                        'tenant_id' => $tenantId,
                        'assessment_setting_id' => $assessmentSettingId,
                        'student_id' => $grade['student_id'],
                        'teacher_id' => $employeeId,
                        'score' => $grade['score'],
                        'notes' => $grade['notes'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                )
                ->all();

            DB::table('student_grades')->upsert(
                $rows,
                [
                    'assessment_setting_id',
                    'student_id',
                ],
                [
                    'teacher_id',
                    'score',
                    'notes',
                    'updated_at',
                ],
            );

            return [
                'employee_id' => $employeeId,
                'processed' => count($rows),
            ];
        });
    }
}
