<?php

namespace Modules\Academic\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\AcademicYearData;
use Modules\Academic\Application\Calendar\ManageCalendars;
use Modules\Academic\Application\Calendar\SaveAcademicYear;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Authorization\SyncAccessCatalog;
use Modules\Core\Application\Membership\AssignToOrganization;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Support\BusinessClock;
use RuntimeException;

/**
 * Data uji modul Academic untuk development (PRD-002). HANYA local/testing.
 *
 * - Akun `operator.smp` (password "password", sama dengan DevAccountsSeeder): Operator Akademik di SMP Unit 3.
 * - Contoh tahun ajaran berjalan di Kalender Yayasan (Juli–Juni, dua semester), bila kalender
 *   itu belum punya tahun ajaran sama sekali.
 *
 * Membutuhkan FirstTenantSeeder sudah dijalankan. Aman dijalankan berulang.
 */
class AcademicDevSeeder extends Seeder
{
    public const OPERATOR_USERNAME = 'operator.smp';

    public const OPERATOR_ORGANIZATION = 'U3-SMP';

    public function __construct(
        private readonly SyncAccessCatalog $syncAccessCatalog,
        private readonly GrantMembership $grantMembership,
        private readonly AssignToOrganization $assignToOrganization,
        private readonly ManageRoles $manageRoles,
        private readonly ManageCalendars $calendars,
        private readonly SaveAcademicYear $saveAcademicYear,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('AcademicDevSeeder hanya boleh dijalankan di lingkungan local/testing.');
        }

        $tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();

        DB::transaction(function () use ($tenant): void {
            // Role Operator Akademik dibutuhkan di bawah; sinkronisasi aman diulang.
            $this->syncAccessCatalog->handle();

            $this->operatorAccount($tenant);
            $this->sampleYear($tenant);
        });
    }

    private function operatorAccount(Tenant $tenant): void
    {
        $user = User::query()->where('username', self::OPERATOR_USERNAME)->first();

        if ($user === null) {
            $person = Person::query()->create(['name' => 'Operator Akademik SMP Unit 3', 'gender' => Gender::Female]);

            $user = User::factory()->create([
                'person_id' => $person->id,
                'email' => 'operator.smp@educore.test',
                'username' => self::OPERATOR_USERNAME,
            ]);
        }

        $membership = $this->grantMembership->handle($user->person_id, $tenant->id);

        $organizationId = Organization::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('code', self::OPERATOR_ORGANIZATION)
            ->valueOrFail('id');

        $assignment = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('membership_id', $membership->id)
            ->where('organization_id', $organizationId)
            ->whereNull('jenjang_filter')
            ->first()
            ?? $this->assignToOrganization->handle($membership, (string) $organizationId);

        $this->manageRoles->grantAssignmentRole($assignment, AcademicAccess::ROLE_OPERATOR_AKADEMIK);
    }

    private function sampleYear(Tenant $tenant): void
    {
        $calendar = $this->calendars->ensureDefault($tenant->id);

        $hasYear = AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $calendar->id)
            ->exists();

        if ($hasYear) {
            return;
        }

        // Tahun ajaran Juli–Juni yang memuat hari ini.
        $today = BusinessClock::today();
        $first = $today->month >= 7 ? $today->year : $today->year - 1;
        $second = $first + 1;

        $this->saveAcademicYear->create($tenant->id, $calendar->id, new AcademicYearData(
            name: "{$first}/{$second}",
            startDate: "{$first}-07-01",
            endDate: "{$second}-06-30",
            terms: [
                ['name' => 'Semester Ganjil', 'start_date' => "{$first}-07-01", 'end_date' => "{$first}-12-31"],
                ['name' => 'Semester Genap', 'start_date' => "{$second}-01-01", 'end_date' => "{$second}-06-30"],
            ],
        ));
    }
}
