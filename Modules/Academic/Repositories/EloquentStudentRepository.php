<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\StudentRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentStudentRepository implements StudentRepositoryInterface
{
    public function getByTenantPaginated(
        string $tenantId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->baseTenantQuery($tenantId)
            ->orderBy('academic_classes.name')
            ->orderBy('persons.name')
            ->paginate($perPage);
    }

    public function findByIdForTenant(
        string $id,
        string $tenantId,
    ): array {
        $student = $this->baseTenantQuery($tenantId)
            ->where('students.id', $id)
            ->first();

        if ($student === null) {
            throw (new ModelNotFoundException())->setModel(
                \Modules\Academic\Models\Student::class,
                [$id],
            );
        }

        return (array) $student;
    }

    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
        array $data,
    ): array {
        $membershipExists = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $membershipExists) {
            throw (new ModelNotFoundException())->setModel(
                \Modules\Core\Authorization\Models\Membership::class,
                [$membershipId],
            );
        }

        $studentId = UuidV7::generate();

        DB::table('students')->insert([
            'id' => $studentId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'class_id' => $data['class_id'] ?? null,
            'nis' => $data['nis'] ?? null,
            'nisn' => $data['nisn'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findByIdForTenant(
            $studentId,
            $tenantId,
        );
    }

    private function baseTenantQuery(string $tenantId): \Illuminate\Database\Query\Builder
    {
        return DB::table('students')
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
                'students.class_id',
                '=',
                'academic_classes.id',
            )
            ->select([
                'students.id as student_id',
                'students.membership_id',
                'memberships.person_id as person_id',
                'students.tenant_id',
                'students.class_id',
                'students.nis',
                'students.nisn',
                'persons.name as nama',
                'academic_classes.name as nama_kelas',
                'academic_classes.tingkat',
                'students.status as student_status',
                'memberships.status as membership_status',
                'students.created_at',
            ])
            ->where('students.tenant_id', $tenantId)
            ->whereNull('students.deleted_at');
    }
}
