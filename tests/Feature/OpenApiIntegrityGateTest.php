<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiIntegrityGateTest extends TestCase
{
    /**
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

    public function test_every_local_openapi_reference_resolves(): void
    {
        $spec = $this->spec();

        $references = array_values(
            array_unique(
                $this->collectReferences(
                    $spec,
                ),
            ),
        );

        $this->assertNotEmpty(
            $references,
            'OpenAPI contract is expected to use reusable local references.',
        );

        foreach ($references as $reference) {
            $this->resolveLocalReference(
                $spec,
                $reference,
            );
        }
    }

    public function test_every_path_template_parameter_is_declared_exactly_once_per_operation(): void
    {
        $spec = $this->spec();

        foreach (
            $this->operations($spec) as $operation
        ) {
            $path = $operation['path'];
            $pathItem = $operation['path_item'];
            $operationDefinition =
                $operation['definition'];

            preg_match_all(
                '/\{([^}]+)\}/',
                $path,
                $matches,
            );

            /** @var array<int, string> $placeholders */
            $placeholders =
                $matches[1] ?? [];

            sort($placeholders);

            $parameters = array_merge(
                is_array(
                    $pathItem['parameters']
                        ?? null,
                )
                    ? $pathItem['parameters']
                    : [],
                is_array(
                    $operationDefinition['parameters']
                        ?? null,
                )
                    ? $operationDefinition['parameters']
                    : [],
            );

            $declaredPathParameters = [];

            foreach ($parameters as $parameter) {
                $this->assertIsArray(
                    $parameter,
                    sprintf(
                        'Invalid parameter definition on [%s %s].',
                        strtoupper(
                            $operation['method'],
                        ),
                        $path,
                    ),
                );

                if (isset($parameter['$ref'])) {
                    $resolved =
                        $this->resolveLocalReference(
                            $spec,
                            (string) $parameter['$ref'],
                        );

                    $this->assertIsArray(
                        $resolved,
                    );

                    $parameter = $resolved;
                }

                if (
                    ($parameter['in'] ?? null)
                    !== 'path'
                ) {
                    continue;
                }

                $name = trim(
                    (string) (
                        $parameter['name']
                        ?? ''
                    ),
                );

                $this->assertNotSame(
                    '',
                    $name,
                    sprintf(
                        'Path parameter name is missing on [%s %s].',
                        strtoupper(
                            $operation['method'],
                        ),
                        $path,
                    ),
                );

                $this->assertTrue(
                    (bool) (
                        $parameter['required']
                        ?? false
                    ),
                    sprintf(
                        'OpenAPI path parameter [%s] must be required on [%s %s].',
                        $name,
                        strtoupper(
                            $operation['method'],
                        ),
                        $path,
                    ),
                );

                $this->assertNotContains(
                    $name,
                    $declaredPathParameters,
                    sprintf(
                        'Duplicate path parameter [%s] on [%s %s].',
                        $name,
                        strtoupper(
                            $operation['method'],
                        ),
                        $path,
                    ),
                );

                $declaredPathParameters[] =
                    $name;
            }

            sort(
                $declaredPathParameters,
            );

            $this->assertSame(
                $placeholders,
                $declaredPathParameters,
                sprintf(
                    'Path template parameter drift detected on [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $path,
                ),
            );
        }
    }

    public function test_every_documented_response_has_json_schema_or_explicit_no_content_contract(): void
    {
        $spec = $this->spec();

        foreach (
            $this->operations($spec) as $operation
        ) {
            $responses =
                $operation['definition']['responses']
                ?? null;

            $this->assertIsArray(
                $responses,
                sprintf(
                    'Responses are missing from [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            $this->assertNotEmpty(
                $responses,
                sprintf(
                    'At least one response is required on [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            foreach (
                $responses as $status => $response
            ) {
                $this->assertIsArray(
                    $response,
                    sprintf(
                        'Invalid response [%s] on [%s %s].',
                        (string) $status,
                        strtoupper(
                            $operation['method'],
                        ),
                        $operation['path'],
                    ),
                );

                if (isset($response['$ref'])) {
                    $resolved =
                        $this->resolveLocalReference(
                            $spec,
                            (string) $response['$ref'],
                        );

                    $this->assertIsArray(
                        $resolved,
                    );

                    $response = $resolved;
                }

                if ((string) $status === '204') {
                    $this->assertArrayNotHasKey(
                        'content',
                        $response,
                        sprintf(
                            'No-content response [%s %s] must not declare a response body.',
                            strtoupper(
                                $operation['method'],
                            ),
                            $operation['path'],
                        ),
                    );

                    continue;
                }

                $schema =
                    $response['content']['application/json']['schema']
                    ?? null;

                $this->assertIsArray(
                    $schema,
                    sprintf(
                        'Response [%s] on [%s %s] must declare an application/json schema.',
                        (string) $status,
                        strtoupper(
                            $operation['method'],
                        ),
                        $operation['path'],
                    ),
                );

                $this->assertNotEmpty(
                    $schema,
                    sprintf(
                        'Response schema [%s] on [%s %s] cannot be empty.',
                        (string) $status,
                        strtoupper(
                            $operation['method'],
                        ),
                        $operation['path'],
                    ),
                );
            }
        }
    }

    public function test_every_declared_request_body_has_an_application_json_schema(): void
    {
        $spec = $this->spec();

        foreach (
            $this->operations($spec) as $operation
        ) {
            if (
                ! array_key_exists(
                    'requestBody',
                    $operation['definition'],
                )
            ) {
                continue;
            }

            $requestBody =
                $operation['definition']['requestBody'];

            $this->assertIsArray(
                $requestBody,
                sprintf(
                    'Invalid requestBody on [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            if (isset($requestBody['$ref'])) {
                $resolved =
                    $this->resolveLocalReference(
                        $spec,
                        (string) $requestBody['$ref'],
                    );

                $this->assertIsArray(
                    $resolved,
                );

                $requestBody = $resolved;
            }

            $schema =
                $requestBody['content']['application/json']['schema']
                ?? null;

            $this->assertIsArray(
                $schema,
                sprintf(
                    'requestBody on [%s %s] must declare an application/json schema.',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            $this->assertNotEmpty(
                $schema,
            );
        }
    }

    public function test_all_foundation_operations_have_operation_id_route_name_and_responses(): void
    {
        $operations =
            $this->operations(
                $this->spec(),
            );

        $this->assertCount(
            22,
            $operations,
        );

        foreach ($operations as $operation) {
            $definition =
                $operation['definition'];

            $operationId = trim(
                (string) (
                    $definition['operationId']
                    ?? ''
                ),
            );

            $routeName = trim(
                (string) (
                    $definition['x-laravel-route-name']
                    ?? ''
                ),
            );

            $this->assertNotSame(
                '',
                $operationId,
                sprintf(
                    'operationId is missing on [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            $this->assertNotSame(
                '',
                $routeName,
                sprintf(
                    'x-laravel-route-name is missing on [%s %s].',
                    strtoupper(
                        $operation['method'],
                    ),
                    $operation['path'],
                ),
            );

            $this->assertArrayHasKey(
                'responses',
                $definition,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectReferences(
        mixed $value,
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $references = [];

        foreach ($value as $key => $child) {
            if (
                $key === '$ref'
                && is_string($child)
            ) {
                $references[] =
                    $child;

                continue;
            }

            $references = array_merge(
                $references,
                $this->collectReferences(
                    $child,
                ),
            );
        }

        return $references;
    }

    /**
     * Resolve an OpenAPI local JSON Pointer reference.
     */
    private function resolveLocalReference(
        array $spec,
        string $reference,
    ): mixed {
        $this->assertStringStartsWith(
            '#/',
            $reference,
            sprintf(
                'External OpenAPI reference is not allowed in the foundation contract: [%s].',
                $reference,
            ),
        );

        $pointer = substr(
            $reference,
            2,
        );

        $this->assertNotSame(
            '',
            $pointer,
            sprintf(
                'Invalid local OpenAPI reference [%s].',
                $reference,
            ),
        );

        $segments = explode(
            '/',
            $pointer,
        );

        $current = $spec;

        foreach ($segments as $segment) {
            /*
             * JSON Pointer escaping:
             * ~1 => /
             * ~0 => ~
             */
            $segment = str_replace(
                [
                    '~1',
                    '~0',
                ],
                [
                    '/',
                    '~',
                ],
                $segment,
            );

            $this->assertIsArray(
                $current,
                sprintf(
                    'Unable to resolve OpenAPI reference [%s] at segment [%s].',
                    $reference,
                    $segment,
                ),
            );

            $this->assertArrayHasKey(
                $segment,
                $current,
                sprintf(
                    'Broken OpenAPI reference [%s]: segment [%s] does not exist.',
                    $reference,
                    $segment,
                ),
            );

            $current =
                $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, array{
     *     method: string,
     *     path: string,
     *     path_item: array<string, mixed>,
     *     definition: array<string, mixed>
     * }>
     */
    private function operations(
        array $spec,
    ): array {
        $operations = [];

        foreach (
            $spec['paths'] ?? [] as $path => $pathItem
        ) {
            $this->assertIsString(
                $path,
            );

            $this->assertIsArray(
                $pathItem,
            );

            foreach (
                self::HTTP_METHODS as $method
            ) {
                if (
                    ! isset(
                        $pathItem[$method],
                    )
                ) {
                    continue;
                }

                $this->assertIsArray(
                    $pathItem[$method],
                );

                $operations[] = [
                    'method' => $method,
                    'path' => $path,
                    'path_item' => $pathItem,
                    'definition' => $pathItem[$method],
                ];
            }
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = base_path(
            'docs/api/openapi.yaml',
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
}
