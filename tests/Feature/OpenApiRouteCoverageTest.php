<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiRouteCoverageTest extends TestCase
{
    private const SPEC_PATH = 'docs/api/openapi.yaml';

    /**
     * OpenAPI operations yang memang merupakan HTTP operations.
     *
     * HEAD Laravel yang otomatis mengikuti GET tidak diperlakukan
     * sebagai OpenAPI operation terpisah.
     *
     * @var array<int, string>
     */
    private const HTTP_METHODS = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'options',
    ];

    public function test_foundation_openapi_document_is_parseable_and_declares_canonical_primitives(): void
    {
        $spec = $this->loadSpec();

        $this->assertSame(
            '3.1.0',
            $spec['openapi'] ?? null,
        );

        $this->assertSame(
            'http',
            $spec['components']['securitySchemes']['BearerAuth']['type']
                ?? null,
        );

        $this->assertSame(
            'bearer',
            $spec['components']['securitySchemes']['BearerAuth']['scheme']
                ?? null,
        );

        $this->assertSame(
            'string',
            $spec['components']['schemas']['UuidV7']['type']
                ?? null,
        );

        $this->assertSame(
            'uuid',
            $spec['components']['schemas']['UuidV7']['format']
                ?? null,
        );

        $this->assertSame(
            'X-EduCore-Organizational-Assignment-Id',
            $spec['components']['parameters']['OrganizationalAssignmentId']['name']
                ?? null,
        );

        $this->assertSame(
            'header',
            $spec['components']['parameters']['OrganizationalAssignmentId']['in']
                ?? null,
        );

        $this->assertTrue(
            (bool) (
                $spec['components']['parameters']['OrganizationalAssignmentId']['required']
                ?? false
            ),
        );
    }

    public function test_all_public_api_v1_routes_are_documented_or_explicitly_deferred(): void
    {
        $actual = $this->laravelApiOperations();
        $documented = $this->documentedOperations();
        $deferred = $this->deferredOperations();

        $this->assertCount(
            36,
            $actual,
            'Expected the current public /api/v1 operation inventory.',
        );

        $this->assertCount(
            19,
            $documented,
            'Foundation OpenAPI must contain exactly the 19 locked foundation and Browser BFF operations.',
        );

        $this->assertCount(
            17,
            $deferred,
            'Academic and HR must contain exactly the 17 explicitly deferred operations.',
        );

        $overlap = array_intersect_key(
            $documented,
            $deferred,
        );

        $this->assertSame(
            [],
            array_keys($overlap),
            sprintf(
                'An API operation cannot be both documented and deferred: %s',
                implode(', ', array_keys($overlap)),
            ),
        );

        $covered = array_merge(
            $documented,
            $deferred,
        );

        ksort($covered);
        ksort($actual);

        $missing = array_diff_key(
            $actual,
            $covered,
        );

        $unknown = array_diff_key(
            $covered,
            $actual,
        );

        $this->assertSame(
            [],
            array_keys($missing),
            sprintf(
                'Public Laravel API operations missing from the OpenAPI contract inventory: %s',
                implode(', ', array_keys($missing)),
            ),
        );

        $this->assertSame(
            [],
            array_keys($unknown),
            sprintf(
                'OpenAPI/deferred operations no longer registered by Laravel: %s',
                implode(', ', array_keys($unknown)),
            ),
        );

        $this->assertSame(
            array_keys($actual),
            array_keys($covered),
        );
    }

    public function test_openapi_and_deferred_inventory_track_canonical_laravel_route_names(): void
    {
        $actual = $this->laravelApiOperations();

        foreach (
            array_merge(
                $this->documentedOperations(),
                $this->deferredOperations(),
            ) as $operationKey => $contract
        ) {
            $this->assertArrayHasKey(
                $operationKey,
                $actual,
            );

            $this->assertNotSame(
                '',
                $contract['route_name'],
                sprintf(
                    'Contract operation [%s] must declare its Laravel route name.',
                    $operationKey,
                ),
            );

            $this->assertSame(
                $actual[$operationKey],
                $contract['route_name'],
                sprintf(
                    'Laravel route name drift detected for [%s].',
                    $operationKey,
                ),
            );
        }
    }

    public function test_documented_openapi_operation_ids_are_unique(): void
    {
        $spec = $this->loadSpec();

        $operationIds = [];

        foreach (
            $spec['paths'] ?? [] as $path => $pathItem
        ) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach (self::HTTP_METHODS as $method) {
                if (
                    ! isset($pathItem[$method])
                    || ! is_array($pathItem[$method])
                ) {
                    continue;
                }

                $operationId = trim(
                    (string) (
                        $pathItem[$method]['operationId']
                        ?? ''
                    ),
                );

                $this->assertNotSame(
                    '',
                    $operationId,
                    sprintf(
                        'OpenAPI operation [%s %s] must define operationId.',
                        strtoupper($method),
                        $path,
                    ),
                );

                $this->assertArrayNotHasKey(
                    $operationId,
                    $operationIds,
                    sprintf(
                        'Duplicate OpenAPI operationId [%s].',
                        $operationId,
                    ),
                );

                $operationIds[$operationId] = true;
            }
        }

        $this->assertCount(
            19,
            $operationIds,
        );
    }

    public function test_workspace_capability_operation_requires_canonical_organizational_header(): void
    {
        $spec = $this->loadSpec();

        $parameters =
            $spec['paths']['/api/v1/core/authorization/workspace-capabilities']['get']['parameters']
            ?? [];

        $this->assertContains(
            [
                '$ref' => '#/components/parameters/OrganizationalAssignmentId',
            ],
            $parameters,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSpec(): array
    {
        $path = base_path(
            self::SPEC_PATH,
        );

        $this->assertFileExists(
            $path,
        );

        $parsed = Yaml::parseFile(
            $path,
        );

        $this->assertIsArray(
            $parsed,
        );

        return $parsed;
    }

    /**
     * @return array<string, array{route_name: string}>
     */
    private function documentedOperations(): array
    {
        $spec = $this->loadSpec();

        $operations = [];

        foreach (
            $spec['paths'] ?? [] as $path => $pathItem
        ) {
            $this->assertIsString(
                $path,
            );

            $this->assertStringStartsWith(
                '/api/v1/',
                $path,
            );

            if (! is_array($pathItem)) {
                continue;
            }

            foreach (self::HTTP_METHODS as $method) {
                if (
                    ! isset($pathItem[$method])
                    || ! is_array($pathItem[$method])
                ) {
                    continue;
                }

                $key = $this->operationKey(
                    strtoupper($method),
                    $path,
                );

                $this->assertArrayNotHasKey(
                    $key,
                    $operations,
                );

                $operations[$key] = [
                    'route_name' => trim(
                        (string) (
                            $pathItem[$method]['x-laravel-route-name']
                            ?? ''
                        ),
                    ),
                ];
            }
        }

        ksort($operations);

        return $operations;
    }

    /**
     * @return array<string, array{route_name: string}>
     */
    private function deferredOperations(): array
    {
        $spec = $this->loadSpec();

        $entries =
            $spec['x-educore-deferred-routes']
            ?? [];

        $this->assertIsArray(
            $entries,
        );

        $operations = [];

        foreach ($entries as $entry) {
            $this->assertIsArray(
                $entry,
            );

            $method = strtoupper(
                trim(
                    (string) (
                        $entry['method']
                        ?? ''
                    ),
                ),
            );

            $path = trim(
                (string) (
                    $entry['path']
                    ?? ''
                ),
            );

            $routeName = trim(
                (string) (
                    $entry['route_name']
                    ?? ''
                ),
            );

            $owner = trim(
                (string) (
                    $entry['owner']
                    ?? ''
                ),
            );

            $reason = trim(
                (string) (
                    $entry['reason']
                    ?? ''
                ),
            );

            $this->assertContains(
                $method,
                array_map(
                    'strtoupper',
                    self::HTTP_METHODS,
                ),
            );

            $this->assertStringStartsWith(
                '/api/v1/',
                $path,
            );

            $this->assertContains(
                $owner,
                [
                    'Academic',
                    'HR',
                ],
            );

            $this->assertSame(
                'domain-api-hardening-deferred',
                $reason,
            );

            $key = $this->operationKey(
                $method,
                $path,
            );

            $this->assertArrayNotHasKey(
                $key,
                $operations,
            );

            $operations[$key] = [
                'route_name' => $routeName,
            ];
        }

        ksort($operations);

        return $operations;
    }

    /**
     * @return array<string, string>
     */
    private function laravelApiOperations(): array
    {
        $operations = [];

        foreach (
            Route::getRoutes()->getRoutes() as $route
        ) {
            $uri = $route->uri();

            if (
                ! str_starts_with(
                    $uri,
                    'api/v1/',
                )
            ) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $method = strtoupper(
                    $method,
                );

                /*
                 * Laravel automatically exposes HEAD for GET.
                 * OpenAPI GET already represents that endpoint.
                 */
                if (
                    in_array(
                        $method,
                        [
                            'HEAD',
                            'OPTIONS',
                        ],
                        true,
                    )
                ) {
                    continue;
                }

                if (
                    ! in_array(
                        strtolower($method),
                        self::HTTP_METHODS,
                        true,
                    )
                ) {
                    continue;
                }

                $key = $this->operationKey(
                    $method,
                    '/'.$uri,
                );

                $this->assertArrayNotHasKey(
                    $key,
                    $operations,
                    sprintf(
                        'Duplicate Laravel API operation detected: [%s].',
                        $key,
                    ),
                );

                $operations[$key] = trim(
                    (string) $route->getName(),
                );
            }
        }

        ksort($operations);

        return $operations;
    }

    private function operationKey(
        string $method,
        string $path,
    ): string {
        return sprintf(
            '%s %s',
            strtoupper(
                trim($method),
            ),
            '/'.ltrim(
                trim($path),
                '/',
            ),
        );
    }
}
