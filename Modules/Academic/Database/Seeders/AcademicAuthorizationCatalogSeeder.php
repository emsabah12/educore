<?php

declare(strict_types=1);

namespace Modules\Academic\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

final class AcademicAuthorizationCatalogSeeder extends Seeder
{
    public const TEACHER_ROLE = 'teacher';

    public const REGISTRAR_ROLE = 'registrar';

    public const GRADES_WRITE_PERMISSION = 'academic.grades.write';

    /**
     * Katalog permission per resource Academic.
     *
     * Key adalah nama permission (canonical, dipakai middleware
     * `tenant.permission:<name>`), value adalah display name untuk UI/admin.
     *
     * @var array<string, string>
     */
    private const RESOURCE_PERMISSIONS = [
        'academic.students.read' => 'View Students',
        'academic.students.write' => 'Manage Students',
        'academic.classes.read' => 'View Academic Classes',
        'academic.classes.write' => 'Manage Academic Classes',
        'academic.subjects.read' => 'View Academic Subjects',
        'academic.subjects.write' => 'Manage Academic Subjects',
        'academic.guardians.read' => 'View Guardians',
        'academic.guardians.write' => 'Manage Guardians',
        'academic.years.read' => 'View Academic Years & Semesters',
        'academic.years.write' => 'Manage Academic Years & Semesters',
    ];

    /**
     * Permission read-only yang diwariskan ke role `teacher`.
     *
     * Guru boleh melihat data akademik untuk keperluan mengajar,
     * tetapi tidak berhak mengubah data induk murid/kelas/tahun ajaran.
     * Perubahan data induk adalah tanggung jawab registrar (TU).
     *
     * @var array<int, string>
     */
    private const TEACHER_READ_PERMISSIONS = [
        'academic.students.read',
        'academic.classes.read',
        'academic.subjects.read',
        'academic.years.read',
    ];

    /**
     * Ensure the Academic authorization catalog exists.
     *
     * Idempoten: aman dijalankan berulang kali (updateOrCreate),
     * tidak pernah menghapus role/permission custom yang sudah
     * dibuat/diubah secara manual di production.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $teacherRole = Role::query()->updateOrCreate(
                ['name' => self::TEACHER_ROLE],
                [
                    'display_name' => 'Teacher',
                    'description' => 'Academic grading capability role.',
                ],
            );

            $registrarRole = Role::query()->updateOrCreate(
                ['name' => self::REGISTRAR_ROLE],
                [
                    'display_name' => 'Registrar',
                    'description' => 'Academic administrative staff (student records, classes, subjects, guardians, academic period).',
                ],
            );

            $gradesWritePermission = Permission::query()->updateOrCreate(
                ['name' => self::GRADES_WRITE_PERMISSION],
                [
                    'display_name' => 'Write Academic Grades',
                    'description' => 'Submit and update student grades.',
                    'module' => 'Academic',
                ],
            );

            $this->grantPermission($teacherRole, $gradesWritePermission);

            $resourcePermissions = [];

            foreach (self::RESOURCE_PERMISSIONS as $name => $displayName) {
                $permission = Permission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $displayName,
                        'description' => sprintf(
                            'Auto-provisioned canonical permission for %s.',
                            $name,
                        ),
                        'module' => 'Academic',
                    ],
                );

                $resourcePermissions[$name] = $permission;

                // Registrar memegang seluruh permission read + write.
                $this->grantPermission($registrarRole, $permission);
            }

            // Teacher hanya menerima subset read-only.
            foreach (self::TEACHER_READ_PERMISSIONS as $name) {
                $this->grantPermission(
                    $teacherRole,
                    $resourcePermissions[$name],
                );
            }
        });
    }

    private function grantPermission(
        Role $role,
        Permission $permission,
    ): void {
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => (string) $role->getKey(),
            'permission_id' => (string) $permission->getKey(),
        ]);
    }
}
