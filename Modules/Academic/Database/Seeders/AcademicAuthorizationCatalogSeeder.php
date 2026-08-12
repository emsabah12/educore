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

    public const GRADES_WRITE_PERMISSION = 'academic.grades.write';

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

            $gradesWritePermission = Permission::query()->updateOrCreate(
                ['name' => self::GRADES_WRITE_PERMISSION],
                [
                    'display_name' => 'Write Academic Grades',
                    'description' => 'Submit and update student grades.',
                    'module' => 'Academic',
                ],
            );

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => (string) $teacherRole->id,
                'permission_id' => (string) $gradesWritePermission->id,
            ]);
        });
    }
}
