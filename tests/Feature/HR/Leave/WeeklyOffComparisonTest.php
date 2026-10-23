<?php

use Inertia\Testing\AssertableInertia as Assert;
use Modules\Academic\Database\Seeders\AcademicCalendarSeeder;
use Modules\Academic\Domain\Calendar\AcademicCalendar;
use Modules\Core\Database\Seeders\DevAccountsSeeder;
use Modules\Core\Database\Seeders\FirstTenantSeeder;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\HR\Database\Seeders\HrLeaveTypeSeeder;
use Tests\Support\CoreFixtures;
use Tests\Support\DevAccounts;

/*
 * Libur mingguan HR tetap terpisah dari kalender akademik, tetapi perbedaannya ditandai di
 * Pengaturan cuti & presensi (PRD-001 §13.2, OD-HR-07 [OWNER, 2026-10-23: opsi A]).
 */

beforeEach(function () {
    $this->seed(FirstTenantSeeder::class);
    $this->seed(DevAccountsSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);
    $this->seed(HrLeaveTypeSeeder::class);

    $this->tenant = Tenant::query()->where('code', FirstTenantSeeder::TENANT_CODE)->firstOrFail();
    $this->admin = DevAccounts::user('admin.yayasan');
    $this->ma = CoreFixtures::node($this->tenant, 'U1-MA')->id;
});

/**
 * @param  list<array{id: string, differs: bool, academic: string|null, days: string}>  $usage
 * @return array{id: string, differs: bool, academic: string|null, days: string}
 */
function lmbRow(array $usage, string $organizationId): array
{
    return collect($usage)->firstWhere('id', $organizationId);
}

test('libur mingguan kalender akademik tampil sebagai pembanding; perbedaan dihitung tanpa mengubah kalender kerja HR', function () {
    $page = fn () => $this->actingAs($this->admin)->get(route('hr.leave-settings.index'));

    $page()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('weeklyOff.differs', 0)
        ->where('weeklyOff.usage', fn ($usage) => lmbRow($usage->all(), $this->ma)['academic'] === 'Ahad'
            && lmbRow($usage->all(), $this->ma)['differs'] === false),
    );

    // Kalender akademik bawaan: libur Jumat & Ahad (mis. pesantren).
    $calendar = AcademicCalendar::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->where('is_default', true)->sole();
    $this->actingAs($this->admin)->post(route('academic.calendar.weekly-off', ['calendar' => $calendar->id]), ['weekly_off_days' => ['5', '7']])
        ->assertSessionHasNoErrors();

    $total = 0;

    $page()->assertInertia(function (Assert $page) use (&$total) {
        $page->where('weeklyOff.usage', function ($usage) use (&$total) {
            $total = count($usage);
            $row = lmbRow($usage->all(), $this->ma);

            return $row['days'] === 'Ahad' && $row['academic'] === 'Jumat, Ahad' && $row['differs'] === true;
        })->where('weeklyOff.differs', fn (int $differs) => $differs === $total && $total > 0);
    });

    // Kalender kerja HR tidak ikut berubah; Admin SDM menyamakan MA secara sengaja.
    $this->actingAs($this->admin)
        ->post(route('hr.weekly-off.assign'), ['target' => 'jenjang', 'jenjang' => 'MA', 'weekly_off_days' => ['7', '5']])
        ->assertSessionHasNoErrors();

    $page()->assertInertia(fn (Assert $page) => $page
        ->where('weeklyOff.usage', fn ($usage) => lmbRow($usage->all(), $this->ma)['differs'] === false
            && lmbRow($usage->all(), $this->ma)['days'] === 'Jumat, Ahad')
        ->where('weeklyOff.differs', fn (int $differs) => $differs < $total),
    );
});
