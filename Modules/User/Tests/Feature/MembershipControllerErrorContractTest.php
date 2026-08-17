<?php

declare(strict_types=1);

namespace Modules\User\Tests\Feature;

use Illuminate\Http\Request;
use Modules\User\Http\Controllers\Api\v1\MembershipController;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class MembershipControllerErrorContractTest extends TestCase
{
    public function test_membership_controller_defensive_guard_uses_canonical_authentication_error(): void
    {
        $controller = $this->app->make(
            MembershipController::class,
        );

        $request = Request::create(
            '/api/v1/user/my-memberships',
            'GET',
        );

        $response = $controller->index(
            $request,
        );

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' =>
                'Unauthenticated. Invalid or missing identity context.',
            ],
            $response->getData(true),
        );
    }
}
