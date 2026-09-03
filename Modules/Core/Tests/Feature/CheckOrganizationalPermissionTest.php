<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Http\Middleware\CheckOrganizationalPermission;
use Modules\Core\Support\Uuid\UuidV7;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class CheckOrganizationalPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var OrganizationalAuthorizationServiceInterface&MockObject */
    private OrganizationalAuthorizationServiceInterface $organizationalAuthorizationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationalAuthorizationService = $this->createMock(
            OrganizationalAuthorizationServiceInterface::class,
        );

        $this->app->instance(
            OrganizationalAuthorizationServiceInterface::class,
            $this->organizationalAuthorizationService,
        );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->organizationalAuthorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $response = $this->middleware()->handle(
            Request::create('/api/protected', 'GET'),
            static fn(Request $request): Response => response()->json([]),
            'hr.workforce.manage',
        );

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
    }

    public function test_superadmin_bypasses_organizational_permission_check(): void
    {
        $this->actingAs(
            $this->createUser(isSuperadmin: true),
        );

        $this->organizationalAuthorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            Request::create('/api/protected', 'GET'),
            static function () use (&$nextWasCalled): Response {
                $nextWasCalled = true;

                return response()->json(['status' => 'success']);
            },
            'hr.workforce.manage',
        );

        $this->assertTrue($nextWasCalled);
        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function test_request_with_granted_permission_passes_through(): void
    {
        $this->actingAs(
            $this->createUser(),
        );

        $this->organizationalAuthorizationService
            ->expects($this->once())
            ->method('hasPermission')
            ->with('hr.workforce.manage')
            ->willReturn(true);

        $response = $this->middleware()->handle(
            Request::create('/api/protected', 'GET'),
            static fn(Request $request): Response => response()->json(['status' => 'success']),
            'hr.workforce.manage',
        );

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function test_request_without_permission_is_forbidden(): void
    {
        $this->actingAs(
            $this->createUser(),
        );

        $this->organizationalAuthorizationService
            ->expects($this->once())
            ->method('hasPermission')
            ->with('hr.workforce.manage')
            ->willReturn(false);

        $nextWasCalled = false;

        $response = $this->middleware()->handle(
            Request::create('/api/protected', 'GET'),
            static function () use (&$nextWasCalled): Response {
                $nextWasCalled = true;

                return response()->json([]);
            },
            'hr.workforce.manage',
        );

        $this->assertFalse($nextWasCalled);
        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
    }

    private function middleware(): CheckOrganizationalPermission
    {
        return new CheckOrganizationalPermission(
            $this->organizationalAuthorizationService,
        );
    }

    private function createUser(bool $isSuperadmin = false): User
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Organizational Permission Fixture User',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf(
                'org-permission-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-middleware-test',
            'status' => 'ACTIVE',
            'is_superadmin' => $isSuperadmin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($userId);
    }
}
