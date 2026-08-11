<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Identity\Models\User;
use Tests\TestCase;

final class LaravelGateIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_laravel_gate_ability_runs_without_tenant_rbac_context(): void
    {
        $user = User::factory()->create();

        Gate::define(
            'core.gate-isolation.allowed',
            static fn (User $candidate): bool => $candidate->is($user),
        );

        $this->actingAs($user);

        $this->assertTrue(
            Gate::allows('core.gate-isolation.allowed'),
        );
    }

    public function test_gate_for_user_evaluates_the_explicit_user_instead_of_current_auth_user(): void
    {
        $currentUser = User::factory()->create();
        $explicitUser = User::factory()->create();

        Gate::define(
            'core.gate-isolation.for-user',
            static fn (User $candidate): bool => $candidate->is($explicitUser),
        );

        $this->actingAs($currentUser);

        $this->assertTrue(
            Gate::forUser($explicitUser)->allows(
                'core.gate-isolation.for-user',
            ),
        );

        $this->assertFalse(
            Gate::forUser($currentUser)->allows(
                'core.gate-isolation.for-user',
            ),
        );
    }
}
