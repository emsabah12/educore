<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Override;
use Throwable;

final class EloquentGuardianStudentRepository implements GuardianStudentRepositoryInterface
{
    /**
     * Menautkan student dengan guardian secara aman
     * dalam tenant yang sama.
     *
     * Operasi bersifat idempotent.
     *
     * @throws ModelNotFoundException
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Override]
    public function attachStudentToGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
        string $relationshipType = 'AYAH'
    ): bool {
        $this->validateIdentifiers(
            $tenantId,
            $guardianId,
            $studentId
        );

        $relationshipType = strtoupper(trim($relationshipType));

        if ($relationshipType === '') {
            throw new InvalidArgumentException(
                'Relationship type cannot be empty.'
            );
        }

        return DB::transaction(function () use (
            $tenantId,
            $guardianId,
            $studentId,
            $relationshipType
        ): bool {
            $guardianExists = DB::table('guardians')
                ->where('id', $guardianId)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $guardianExists) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        'Modules\\Auth\\Entities\\Guardian',
                        [$guardianId]
                    );
            }

            $studentExists = DB::table('students')
                ->where('id', $studentId)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $studentExists) {
                throw (new ModelNotFoundException())
                    ->setModel(
                        'Modules\\Auth\\Entities\\Student',
                        [$studentId]
                    );
            }

            $alreadyAttached = DB::table('guardian_student')
                ->where('tenant_id', $tenantId)
                ->where('guardian_id', $guardianId)
                ->where('student_id', $studentId)
                ->exists();

            if ($alreadyAttached) {
                return true;
            }

            return DB::table('guardian_student')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'guardian_id' => $guardianId,
                'student_id' => $studentId,
                'relationship_type' => $relationshipType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Memutuskan hubungan guardian dengan student
     * secara tenant-aware.
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public function detachStudentFromGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId
    ): bool {
        $this->validateIdentifiers(
            $tenantId,
            $guardianId,
            $studentId
        );

        $affectedRows = DB::table('guardian_student')
            ->where('tenant_id', $tenantId)
            ->where('guardian_id', $guardianId)
            ->where('student_id', $studentId)
            ->delete();

        return $affectedRows > 0;
    }

    /**
     * Mengambil daftar student berdasarkan guardian
     * dalam tenant yang sama.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public function getStudentsByGuardian(
        string $tenantId,
        string $guardianId
    ): array {
        $this->validateIdentifiers(
            $tenantId,
            $guardianId
        );

        return DB::table('guardian_student')
            ->join(
                'students',
                'guardian_student.student_id',
                '=',
                'students.id'
            )
            ->join(
                'memberships',
                'students.membership_id',
                '=',
                'memberships.id'
            )
            ->join(
                'users',
                'memberships.user_id',
                '=',
                'users.id'
            )
            ->join(
                'academic_classes',
                'students.class_id',
                '=',
                'academic_classes.id'
            )
            ->select([
                'students.id as student_id',
                'students.nis',
                'users.name as student_name',
                'academic_classes.name as class_name',
                'guardian_student.relationship_type',
                'guardian_student.created_at',
            ])
            ->where(
                'guardian_student.tenant_id',
                $tenantId
            )
            ->where(
                'guardian_student.guardian_id',
                $guardianId
            )
            ->where(
                'students.tenant_id',
                $tenantId
            )
            ->where(
                'memberships.tenant_id',
                $tenantId
            )
            ->whereNull('students.deleted_at')
            ->orderBy('users.name')
            ->get()
            ->map(
                static fn(object $student): array => [
                    'student_id' => $student->student_id,
                    'nis' => $student->nis,
                    'student_name' => $student->student_name,
                    'class_name' => $student->class_name,
                    'relationship_type' => $student->relationship_type,
                    'created_at' => $student->created_at,
                ]
            )
            ->all();
    }

    /**
     * Validasi identifier dasar sebelum query database.
     *
     * @param string ...$identifiers
     */
    private function validateIdentifiers(
        string ...$identifiers
    ): void {
        foreach ($identifiers as $identifier) {
            if (trim($identifier) === '') {
                throw new InvalidArgumentException(
                    'Tenant and entity identifiers cannot be empty.'
                );
            }
        }
    }
}
