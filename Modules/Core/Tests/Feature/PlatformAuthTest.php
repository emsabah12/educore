<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Identity\Models\User;
use Tests\TestCase;

final class PlatformAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_is_visible_to_guests(): void
    {
        $this->get('/platform/login')
            ->assertOk()
            ->assertSee('EduCore Platform');
    }

    public function test_superadmin_can_login_and_reach_dashboard(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $response = $this->post('/platform/login', [
            'identifier' => $superadmin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('platform.dashboard'));
        $this->assertAuthenticatedAs($superadmin, 'web');

        $this->get(route('platform.dashboard'))
            ->assertOk()
            ->assertSee('Selamat datang');
    }

    public function test_non_superadmin_with_correct_password_is_rejected_with_generic_error(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $response = $this->post('/platform/login', [
            'identifier' => $regularUser->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['identifier' => 'Kredensial tidak valid.']);
        $this->assertGuest('web');
    }

    public function test_login_rejects_wrong_password(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $response = $this->post('/platform/login', [
            'identifier' => $superadmin->email,
            'password' => 'salah-password',
        ]);

        $response->assertSessionHasErrors(['identifier' => 'Kredensial tidak valid.']);
        $this->assertGuest('web');
    }

    public function test_dashboard_redirects_guests_to_login(): void
    {
        $this->get(route('platform.dashboard'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_dashboard_is_forbidden_for_authenticated_non_superadmin(): void
    {
        $regularUser = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($regularUser, 'web')
            ->get(route('platform.dashboard'))
            ->assertForbidden();
    }

    public function test_superadmin_can_logout(): void
    {
        $superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->actingAs($superadmin, 'web');

        $response = $this->post(route('platform.logout'));

        $response->assertRedirect(route('platform.login'));
        $this->assertGuest('web');
    }
}
