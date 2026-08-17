<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiRouteNamespaceTest extends TestCase
{
    public function test_academic_and_hr_routes_use_canonical_api_v1_prefix(): void
    {
        $moduleRoutePrefixes = [
            'api.v1.academic.' => 'api/v1/academic/',
            'api.v1.hr.' => 'api/v1/hr/',
        ];

        foreach ($moduleRoutePrefixes as $namePrefix => $uriPrefix) {
            $routes = collect(Route::getRoutes()->getRoutes())
                ->filter(
                    static function (
                        IlluminateRoute $route
                    ) use ($namePrefix): bool {
                        $name = $route->getName();

                        return is_string($name)
                            && str_starts_with(
                                $name,
                                $namePrefix,
                            );
                    },
                );

            $this->assertNotEmpty(
                $routes,
                sprintf(
                    'Expected at least one route named with prefix [%s].',
                    $namePrefix,
                ),
            );

            foreach ($routes as $route) {
                $this->assertStringStartsWith(
                    $uriPrefix,
                    $route->uri(),
                    sprintf(
                        'Route [%s] must use canonical URI prefix [%s].',
                        (string) $route->getName(),
                        $uriPrefix,
                    ),
                );
            }
        }
    }
}
