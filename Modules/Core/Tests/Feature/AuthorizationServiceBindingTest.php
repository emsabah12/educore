<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Services\AuthorizationService;
use Tests\TestCase;

final class AuthorizationServiceBindingTest extends TestCase
{
    public function test_authorization_service_interface_resolves_to_concrete_implementation(): void
    {
        $service = app(AuthorizationServiceInterface::class);

        $this->assertInstanceOf(
            AuthorizationService::class,
            $service
        );
    }
}
