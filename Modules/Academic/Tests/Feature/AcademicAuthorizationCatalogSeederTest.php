<?php

declare(strict_types=1);

namespace Modules\Academic\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Database\Seeders\AcademicAuthorizationCatalogSeeder;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AcademicAuthorizationCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    public static function resourcePermissionProvider(): array
    {
        return [
            'students read' => ['academic.students.read'],
            'students write' => ['academic.students.write'],
            'classes read' => ['academic.classes.read'],
            'classes write' => ['academic.classes.write'],
            'subjects read' => ['academic.subjects.read'],
            'subjects write' => ['academic.subjects.write'],
            'guardians read' => ['academic.guardians.read'],
            'guardians write' => ['academic.guardians.write'],
            'years read' => ['academic.years.read'],
            'years write' => ['academic.years.write'],
        ];
    }

    #[DataProvider('resourcePermissionProvider')]
    public function test_seeder_creates_expected_permission(string $permissionName): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $this->assertDatabaseHas('permissions', [
            'name' => $permissionName,
            'module' => 'Academic',
        ]);
    }

    public function test_seeder_creates_registrar_role_with_full_read_write_access(): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $registrar = Role::query()
            ->where('name', AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE)
            ->sole();

        $this->assertSame('Registrar', $registrar->display_name);

        $grantedPermissionNames = $registrar->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $expected = collect(self::resourcePermissionProvider())
            ->map(static fn(array $case): string => $case[0])
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $grantedPermissionNames);
    }

    public function test_seeder_grants_teacher_role_read_only_academic_access(): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $teacher = Role::query()
            ->where('name', AcademicAuthorizationCatalogSeeder::TEACHER_ROLE)
            ->sole();

        $grantedPermissionNames = $teacher->permissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        // Teacher memegang 4 permission read baru DITAMBAH
        // academic.grades.write dari kontrak lama (regresi tidak boleh
        // terjadi terhadap fitur bulk grading yang sudah ada).
        $this->assertSame(
            [
                'academic.classes.read',
                'academic.grades.write',
                'academic.students.read',
                'academic.subjects.read',
                'academic.years.read',
            ],
            $grantedPermissionNames,
        );
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);
        $this->seed(AcademicAuthorizationCatalogSeeder::class);
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $this->assertSame(
            1,
            Role::query()->where('name', AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE)->count(),
        );

        $this->assertSame(
            1,
            Permission::query()->where('name', 'academic.students.write')->count(),
        );

        $registrar = Role::query()
            ->where('name', AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE)
            ->sole();

        // 10 permission, tidak boleh terduplikasi di role_permissions
        // meskipun seeder dijalankan berkali-kali.
        $this->assertSame(
            10,
            DB::table('role_permissions')
                ->where('role_id', $registrar->getKey())
                ->count(),
        );
    }

    public function test_seeder_preserves_existing_role_id_across_reseed(): void
    {
        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $originalId = (string) Role::query()
            ->where('name', AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE)
            ->sole()
            ->getKey();

        $this->seed(AcademicAuthorizationCatalogSeeder::class);

        $reseededId = (string) Role::query()
            ->where('name', AcademicAuthorizationCatalogSeeder::REGISTRAR_ROLE)
            ->sole()
            ->getKey();

        $this->assertSame($originalId, $reseededId);
    }
}
