<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Override;

final class EloquentGuardianStudentRepository implements GuardianStudentRepositoryInterface
{
    #[Override]
    public function attachStudentToGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
        string $relationshipType,
    ): bool {
        $this->validateUuidV7Identifiers(
            $tenantId,
            $guardianId,
            $studentId,
        );

        $normalizedRelationshipType = strtoupper(
            trim($relationshipType),
        );

        if ($normalizedRelationshipType === '') {
            throw new InvalidArgumentException(
                'Relationship type cannot be empty.',
            );
        }

        if (mb_strlen($normalizedRelationshipType) > 50) {
            throw new InvalidArgumentException(
                'Relationship type cannot exceed 50 characters.',
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $guardianId,
            $studentId,
            $normalizedRelationshipType,
        ): bool {
            $this->assertGuardianInTenant(
                $tenantId,
                $guardianId,
            );
            $this->assertStudentInTenant(
                $tenantId,
                $studentId,
            );

            $insertedRows = DB::table('guardian_student')
                ->insertOrIgnore([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'guardian_id' => $guardianId,
                    'student_id' => $studentId,
                    'relationship_type' => $normalizedRelationshipType,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            return $insertedRows > 0;
        });
    }

    #[Override]
    public function detachStudentFromGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
    ): bool {
        $this->validateUuidV7Identifiers(
            $tenantId,
            $guardianId,
            $studentId,
        );

        $affectedRows = DB::table('guardian_student')
            ->where('tenant_id', $tenantId)
            ->where('guardian_id', $guardianId)
            ->where('student_id', $studentId)
            ->delete();

        return $affectedRows > 0;
    }

    #[Override]
    public function getStudentsByGuardian(
        string $tenantId,
        string $guardianId,
    ): array {
        $this->validateUuidV7Identifiers(
            $tenantId,
            $guardianId,
        );

        $this->assertGuardianInTenant(
            $tenantId,
            $guardianId,
        );

        return DB::table('guardian_student')
            ->join(
                'students',
                'guardian_student.student_id',
                '=',
                'students.id',
            )
            ->join(
                'memberships',
                'students.membership_id',
                '=',
                'memberships.id',
            )
            ->join(
                'persons',
                'memberships.person_id',
                '=',
                'persons.id',
            )
            ->leftJoin(
                'academic_classes',
                static function (JoinClause $join) use ($tenantId): void {
                    $join->on(
                        'students.class_id',
                        '=',
                        'academic_classes.id',
                    )
                        ->where(
                            'academic_classes.tenant_id',
                            '=',
                            $tenantId,
                        )
                        ->whereNull('academic_classes.deleted_at');
                },
            )
            ->select([
                'students.id as student_id',
                'students.membership_id',
                'memberships.person_id',
                'students.class_id',
                'students.nis',
                'students.nisn',
                'persons.name as nama',
                'academic_classes.name as nama_kelas',
                'academic_classes.tingkat',
                'students.status as student_status',
                'memberships.status as membership_status',
                'guardian_student.relationship_type',
                'guardian_student.created_at as relationship_created_at',
            ])
            ->where(
                'guardian_student.tenant_id',
                $tenantId,
            )
            ->where(
                'guardian_student.guardian_id',
                $guardianId,
            )
            ->where(
                'students.tenant_id',
                $tenantId,
            )
            ->where(
                'memberships.tenant_id',
                $tenantId,
            )
            ->whereNull('students.deleted_at')
            ->orderBy('persons.name')
            ->orderBy('students.id')
            ->get()
            ->map(
                static fn (object $student): array => [
                    'student_id' => (string) $student->student_id,
                    'membership_id' => (string) $student->membership_id,
                    'person_id' => (string) $student->person_id,
                    'class_id' => is_string($student->class_id)
                        ? $student->class_id
                        : null,
                    'nis' => is_string($student->nis)
                        ? $student->nis
                        : null,
                    'nisn' => is_string($student->nisn)
                        ? $student->nisn
                        : null,
                    'nama' => (string) $student->nama,
                    'nama_kelas' => is_string($student->nama_kelas)
                        ? $student->nama_kelas
                        : null,
                    'tingkat' => is_string($student->tingkat)
                        ? $student->tingkat
                        : null,
                    'student_status' => (string) $student->student_status,
                    'membership_status' => (string) $student->membership_status,
                    'relationship_type' => (string) $student->relationship_type,
                    'relationship_created_at' => $student->relationship_created_at,
                ],
            )
            ->all();
    }

    private function assertGuardianInTenant(
        string $tenantId,
        string $guardianId,
    ): void {
        $exists = DB::table('guardians')
            ->join(
                'memberships',
                'guardians.membership_id',
                '=',
                'memberships.id',
            )
            ->where('guardians.id', $guardianId)
            ->where('guardians.tenant_id', $tenantId)
            ->where('memberships.tenant_id', $tenantId)
            ->whereNull('guardians.deleted_at')
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException())
                ->setModel(
                    'Modules\\Academic\\Models\\Guardian',
                    [$guardianId],
                );
        }
    }

    private function assertStudentInTenant(
        string $tenantId,
        string $studentId,
    ): void {
        $exists = DB::table('students')
            ->join(
                'memberships',
                'students.membership_id',
                '=',
                'memberships.id',
            )
            ->where('students.id', $studentId)
            ->where('students.tenant_id', $tenantId)
            ->where('memberships.tenant_id', $tenantId)
            ->whereNull('students.deleted_at')
            ->exists();

        if (! $exists) {
            throw (new ModelNotFoundException())
                ->setModel(
                    'Modules\\Academic\\Models\\Student',
                    [$studentId],
                );
        }
    }

    private function validateUuidV7Identifiers(
        string ...$identifiers,
    ): void {
        foreach ($identifiers as $identifier) {
            if (! UuidV7::validate(trim($identifier))) {
                throw new InvalidArgumentException(
                    'Tenant and entity identifiers must be valid UUIDv7 values.',
                );
            }
        }
    }
}
