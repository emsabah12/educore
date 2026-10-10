<?php

namespace Tests\Support;

use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Tests\TestCase;

/**
 * Bantuan test untuk akun uji DevAccountsSeeder.
 */
final class DevAccounts
{
    public static function user(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    /**
     * Pilih lembaga kerja lewat halaman resmi, untuk akun dengan lebih dari satu pilihan.
     * $organizationCode null = penugasan tingkat Yayasan (fungsional).
     */
    public static function chooseWorkspace(TestCase $test, User $user, ?string $organizationCode, ?Jenjang $jenjang = null): void
    {
        $assignment = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->whereHas('membership', fn ($membership) => $membership->where('person_id', $user->person_id))
            ->where('jenjang_filter', $jenjang?->value)
            ->when(
                $organizationCode === null,
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->whereHas('organization', fn ($organization) => $organization
                    ->withoutGlobalScope('tenant')
                    ->where('code', $organizationCode)),
            )
            ->sole();

        // Request pertama membentuk pilihan yayasan di session.
        $test->actingAs($user)->get(route('dashboard'));
        $test->actingAs($user)
            ->post(route('context.workspace.update'), ['assignment_id' => $assignment->id])
            ->assertRedirect(route('dashboard'));
    }
}
