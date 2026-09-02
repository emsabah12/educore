<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiOperationContractTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const SUCCESS_SCHEMAS = [
        'POST /api/v1/auth/login-token' => '#/components/schemas/LoginTokenSuccess',

        'POST /api/v1/auth/logout' => '#/components/schemas/LogoutSuccess',

        'GET /api/v1/auth/me' => '#/components/schemas/AuthenticatedBootstrapSuccess',

        'GET /api/v1/auth/identity' => '#/components/schemas/GlobalIdentitySuccess',

        'POST /api/v1/browser/auth/login' => '#/components/schemas/BrowserLoginSuccess',

        'POST /api/v1/browser/auth/logout' => '#/components/schemas/BrowserLogoutSuccess',

        'POST /api/v1/browser/user/memberships/{membership_id}/switch' => '#/components/schemas/BrowserMembershipSwitchSuccess',

        'GET /api/v1/core/authorization/capabilities' => '#/components/schemas/TenantCapabilitySuccess',

        'GET /api/v1/core/authorization/workspace-capabilities' => '#/components/schemas/WorkspaceCapabilitySuccess',

        'GET /api/v1/core/authorization/roles' => '#/components/schemas/RoleCatalogSuccess',

        'GET /api/v1/core/health' => '#/components/schemas/HealthStatus',

        'POST /api/v1/core/notifications/dispatch' => '#/components/schemas/NotificationDispatchSuccess',

        'GET /api/v1/core/tenants' => '#/components/schemas/TenantListSuccess',

        'POST /api/v1/core/tenants' => '#/components/schemas/TenantCreatedSuccess',

        'PUT /api/v1/core/tenants/{id}' => '#/components/schemas/TenantUpdatedSuccess',

        'GET /api/v1/hr/employees' => '#/components/schemas/EmployeeListSuccess',

        'POST /api/v1/hr/employees' => '#/components/schemas/EmployeeCreatedSuccess',

        'GET /api/v1/user/my-memberships' => '#/components/schemas/MembershipListSuccess',

        'POST /api/v1/user/memberships/{membership_id}/switch' => '#/components/schemas/MembershipSwitchSuccess',

        'GET /api/v1/user/my-workspaces' => '#/components/schemas/WorkspaceDiscoverySuccess',

        'POST /api/v1/user/memberships/{target_membership_id}/assign-role' => '#/components/schemas/MembershipRoleAssignmentSuccess',
    ];

    /**
     * @var array<string, string>
     */
    private const SUCCESS_STATUSES = [
        'POST /api/v1/auth/login-token' => '200',
        'POST /api/v1/auth/logout' => '200',
        'GET /api/v1/auth/me' => '200',
        'GET /api/v1/auth/identity' => '200',
        'POST /api/v1/browser/auth/login' => '200',
        'POST /api/v1/browser/auth/logout' => '200',
        'POST /api/v1/browser/user/memberships/{membership_id}/switch' => '200',
        'GET /api/v1/core/authorization/capabilities' => '200',
        'GET /api/v1/core/authorization/workspace-capabilities' => '200',
        'GET /api/v1/core/authorization/roles' => '200',
        'GET /api/v1/core/health' => '200',
        'POST /api/v1/core/notifications/dispatch' => '202',
        'GET /api/v1/core/tenants' => '200',
        'POST /api/v1/core/tenants' => '201',
        'PUT /api/v1/core/tenants/{id}' => '200',
        'GET /api/v1/hr/employees' => '200',
        'POST /api/v1/hr/employees' => '201',
        'GET /api/v1/user/my-memberships' => '200',
        'POST /api/v1/user/memberships/{membership_id}/switch' => '200',
        'GET /api/v1/user/my-workspaces' => '200',
        'POST /api/v1/user/memberships/{target_membership_id}/assign-role' => '200',
    ];

    /**
     * @var array<string, string>
     */
    private const REQUEST_BODY_SCHEMAS = [
        'POST /api/v1/auth/login-token' => '#/components/schemas/LoginTokenRequest',

        'POST /api/v1/browser/auth/login' => '#/components/schemas/LoginTokenRequest',

        'POST /api/v1/core/notifications/dispatch' => '#/components/schemas/NotificationDispatchRequest',

        'POST /api/v1/core/tenants' => '#/components/schemas/StoreTenantRequest',

        'PUT /api/v1/core/tenants/{id}' => '#/components/schemas/UpdateTenantRequest',

        'POST /api/v1/hr/employees' => '#/components/schemas/StoreEmployeeRequest',

        'POST /api/v1/user/memberships/{target_membership_id}/assign-role' => '#/components/schemas/MembershipRoleAssignmentRequest',
    ];

    public function test_all_21_json_foundation_operations_have_exact_success_schema_wiring(): void
    {
        $this->assertCount(
            21,
            self::SUCCESS_SCHEMAS,
        );

        foreach (
            self::SUCCESS_SCHEMAS as $operationKey => $schemaRef
        ) {
            $operation = $this->operation(
                $operationKey,
            );

            $status =
                self::SUCCESS_STATUSES[$operationKey];

            $this->assertSame(
                $schemaRef,
                $operation['responses'][$status]['content']['application/json']['schema']['$ref']
                    ?? null,
                sprintf(
                    'Unexpected success schema for [%s].',
                    $operationKey,
                ),
            );
        }
    }

    public function test_browser_csrf_bootstrap_is_explicit_no_content_success(): void
    {
        $operation = $this->operation(
            'GET /api/v1/browser/session/csrf',
        );

        $this->assertArrayHasKey(
            '204',
            $operation['responses'],
        );

        $this->assertArrayNotHasKey(
            'content',
            $operation['responses']['204'],
        );
    }

    public function test_exact_request_body_schemas_are_wired_to_mutating_operations(): void
    {
        foreach (
            self::REQUEST_BODY_SCHEMAS as $operationKey => $schemaRef
        ) {
            $operation = $this->operation(
                $operationKey,
            );

            $this->assertTrue(
                (bool) (
                    $operation['requestBody']['required']
                    ?? false
                ),
                sprintf(
                    'Request body must be required for [%s].',
                    $operationKey,
                ),
            );

            $this->assertSame(
                $schemaRef,
                $operation['requestBody']['content']['application/json']['schema']['$ref']
                    ?? null,
                sprintf(
                    'Unexpected request schema for [%s].',
                    $operationKey,
                ),
            );
        }

        /*
         * Membership switch is a path-driven token exchange.
         * It intentionally has no request body.
         */
        $this->assertArrayNotHasKey(
            'requestBody',
            $this->operation(
                'POST /api/v1/user/memberships/{membership_id}/switch',
            ),
        );

        $this->assertArrayNotHasKey(
            'requestBody',
            $this->operation(
                'POST /api/v1/browser/user/memberships/{membership_id}/switch',
            ),
        );
    }

    public function test_public_and_authenticated_security_boundaries_are_explicit(): void
    {
        foreach (
            [
                'POST /api/v1/auth/login-token',
                'GET /api/v1/core/health',
                'GET /api/v1/browser/session/csrf',
                'POST /api/v1/browser/auth/login',
            ] as $operationKey
        ) {
            $this->assertSame(
                [],
                $this->operation(
                    $operationKey,
                )['security'] ?? null,
                sprintf(
                    'Public security drift detected for [%s].',
                    $operationKey,
                ),
            );
        }

        foreach (
            [
                'POST /api/v1/browser/user/memberships/{membership_id}/switch',
            ] as $operationKey
        ) {
            $this->assertSame(
                [
                    [
                        'BrowserSessionAuth' => [],
                    ],
                ],
                $this->operation(
                    $operationKey,
                )['security'] ?? null,
                sprintf(
                    'Browser Session security drift detected for [%s].',
                    $operationKey,
                ),
            );
        }

        $this->assertSame(
            [
                [
                    'BrowserSessionAuth' => [],
                ],
                [],
            ],
            $this->operation(
                'POST /api/v1/browser/auth/logout',
            )['security'] ?? null,
            'Browser logout must remain idempotent for an anonymous session while accepting BrowserSessionAuth.',
        );

        $canonicalDualTransportOperations = [
            'GET /api/v1/auth/me',
            'GET /api/v1/auth/identity',
            'GET /api/v1/core/authorization/capabilities',
            'GET /api/v1/core/authorization/workspace-capabilities',
            'GET /api/v1/user/my-memberships',
            'GET /api/v1/user/my-workspaces',
        ];

        foreach ($canonicalDualTransportOperations as $operationKey) {
            $this->assertSame(
                [
                    [
                        'BearerAuth' => [],
                    ],
                    [
                        'BrowserSessionAuth' => [],
                    ],
                ],
                $this->operation(
                    $operationKey,
                )['security'] ?? null,
                sprintf(
                    'Canonical dual-transport security drift detected for [%s].',
                    $operationKey,
                ),
            );
        }

        $browserOperations = [
            'POST /api/v1/browser/auth/login',
            'POST /api/v1/browser/auth/logout',
            'POST /api/v1/browser/user/memberships/{membership_id}/switch',
        ];

        foreach (
            array_keys(
                self::SUCCESS_SCHEMAS,
            ) as $operationKey
        ) {
            if (
                in_array(
                    $operationKey,
                    array_merge(
                        [
                            'POST /api/v1/auth/login-token',
                            'GET /api/v1/core/health',
                        ],
                        $browserOperations,
                        $canonicalDualTransportOperations,
                    ),
                    true,
                )
            ) {
                continue;
            }

            $this->assertSame(
                [
                    [
                        'BearerAuth' => [],
                    ],
                ],
                $this->operation(
                    $operationKey,
                )['security'] ?? null,
                sprintf(
                    'Bearer security drift detected for [%s].',
                    $operationKey,
                ),
            );
        }
    }

    public function test_context_and_path_parameters_are_wired_to_canonical_components(): void
    {
        $expected = [
            'GET /api/v1/core/authorization/workspace-capabilities' => '#/components/parameters/OrganizationalAssignmentId',

            'GET /api/v1/core/tenants' => '#/components/parameters/TenantPerPage',

            'PUT /api/v1/core/tenants/{id}' => '#/components/parameters/TenantId',

            'POST /api/v1/user/memberships/{membership_id}/switch' => '#/components/parameters/MembershipId',

            'POST /api/v1/browser/user/memberships/{membership_id}/switch' => '#/components/parameters/BrowserMembershipPathId',

            'POST /api/v1/user/memberships/{target_membership_id}/assign-role' => '#/components/parameters/TargetMembershipId',
        ];

        foreach (
            $expected as $operationKey => $parameterRef
        ) {
            $parameters =
                $this->operation(
                    $operationKey,
                )['parameters']
                ?? [];

            $this->assertContains(
                [
                    '$ref' => $parameterRef,
                ],
                $parameters,
                sprintf(
                    'Required parameter missing from [%s].',
                    $operationKey,
                ),
            );
        }

        foreach (
            [
                'GET /api/v1/auth/me',
                'GET /api/v1/core/authorization/capabilities',
                'GET /api/v1/core/authorization/workspace-capabilities',
                'GET /api/v1/user/my-workspaces',
            ] as $operationKey
        ) {
            $this->assertContains(
                [
                    '$ref' => '#/components/parameters/CanonicalBrowserMembershipLocator',
                ],
                $this->operation(
                    $operationKey,
                )['parameters'] ?? [],
                sprintf(
                    'Conditional Browser Membership locator missing from [%s].',
                    $operationKey,
                ),
            );
        }

        $this->assertArrayNotHasKey(
            'parameters',
            $this->operation(
                'GET /api/v1/user/my-memberships',
            ),
            'Membership discovery must not require or advertise a Membership locator.',
        );
    }

    public function test_canonical_browser_membership_locator_is_optional_and_transitional_locator_is_retired(): void
    {
        $parameters = $this->spec()['components']['parameters']
            ?? [];

        $canonical =
            $parameters['CanonicalBrowserMembershipLocator']
            ?? [];

        $this->assertSame(
            'X-EduCore-Membership-Id',
            $canonical['name'] ?? null,
        );

        $this->assertSame(
            'header',
            $canonical['in'] ?? null,
        );

        $this->assertFalse(
            (bool) ($canonical['required'] ?? true),
        );

        $this->assertSame(
            '#/components/schemas/UuidV7',
            $canonical['schema']['$ref'] ?? null,
        );

        $this->assertArrayNotHasKey(
            'BrowserMembershipLocator',
            $parameters,
            'Transitional Browser Membership locator must remain retired.',
        );
    }

    public function test_canonical_dual_transport_errors_preserve_transport_specific_machine_codes(): void
    {
        $tenantScopedOperations = [
            'GET /api/v1/auth/me',
            'GET /api/v1/user/my-workspaces',
            'GET /api/v1/core/authorization/capabilities',
        ];

        $tenantForbiddenSchemas = [
            [
                '$ref' => '#/components/schemas/AuthenticationContextDeniedError',
            ],
            [
                '$ref' => '#/components/schemas/BrowserMembershipContextRequiredError',
            ],
            [
                '$ref' => '#/components/schemas/BrowserMembershipContextDeniedError',
            ],
            [
                '$ref' => '#/components/schemas/BrowserSessionContextMismatchError',
            ],
        ];

        foreach ($tenantScopedOperations as $operationKey) {
            $operation = $this->operation(
                $operationKey,
            );

            $this->assertSame(
                '#/components/responses/BrowserSessionAuthenticationRequired',
                $operation['responses']['401']['$ref']
                    ?? null,
                sprintf(
                    'Browser authentication error drift detected for [%s].',
                    $operationKey,
                ),
            );

            $this->assertSame(
                $tenantForbiddenSchemas,
                $operation['responses']['403']['content']['application/json']['schema']['oneOf']
                    ?? [],
                sprintf(
                    'Tenant/Browser forbidden error drift detected for [%s].',
                    $operationKey,
                ),
            );

            $this->assertSame(
                '#/components/responses/InvalidBrowserMembershipId',
                $operation['responses']['422']['$ref']
                    ?? null,
                sprintf(
                    'Browser Membership validation error drift detected for [%s].',
                    $operationKey,
                ),
            );

            $this->assertSame(
                '#/components/responses/BrowserSessionUnavailable',
                $operation['responses']['503']['$ref']
                    ?? null,
                sprintf(
                    'Browser Session availability error drift detected for [%s].',
                    $operationKey,
                ),
            );
        }

        foreach (
            [
                'GET /api/v1/user/my-workspaces',
                'GET /api/v1/core/authorization/capabilities',
            ] as $operationKey
        ) {
            $this->assertSame(
                '#/components/responses/InternalServerError',
                $this->operation(
                    $operationKey,
                )['responses']['500']['$ref'] ?? null,
                sprintf(
                    'Canonical server error drift detected for [%s].',
                    $operationKey,
                ),
            );
        }

        $membershipDiscovery = $this->operation(
            'GET /api/v1/user/my-memberships',
        );

        $membershipUnauthorizedSchemas =
            $membershipDiscovery['responses']['401']['content']['application/json']['schema']['oneOf']
            ?? [];

        $this->assertSame(
            [
                [
                    '$ref' => '#/components/schemas/AuthenticationRequiredError',
                ],
                [
                    '$ref' => '#/components/schemas/BrowserSessionAuthenticationRequiredError',
                ],
            ],
            $membershipUnauthorizedSchemas,
        );

        $this->assertSame(
            '#/components/schemas/BrowserSessionContextMismatchError',
            $membershipDiscovery['responses']['403']['content']['application/json']['schema']['$ref']
                ?? null,
        );

        $this->assertSame(
            '#/components/responses/InternalServerError',
            $membershipDiscovery['responses']['500']['$ref']
                ?? null,
        );

        $this->assertSame(
            '#/components/responses/BrowserSessionUnavailable',
            $membershipDiscovery['responses']['503']['$ref']
                ?? null,
        );
    }

    public function test_health_503_keeps_health_status_representation(): void
    {
        $operation = $this->operation(
            'GET /api/v1/core/health',
        );

        $this->assertSame(
            '#/components/schemas/HealthStatus',
            $operation['responses']['503']['content']['application/json']['schema']['$ref']
                ?? null,
        );
    }

    public function test_workspace_context_errors_preserve_distinct_machine_code_schemas(): void
    {
        $operation = $this->operation(
            'GET /api/v1/core/authorization/workspace-capabilities',
        );

        $this->assertSame(
            '#/components/responses/BrowserSessionAuthenticationRequired',
            $operation['responses']['401']['$ref']
                ?? null,
        );

        $forbiddenSchemas =
            $operation['responses']['403']['content']['application/json']['schema']['oneOf']
            ?? [];

        $this->assertSame(
            [
                [
                    '$ref' => '#/components/schemas/AuthenticationContextDeniedError',
                ],
                [
                    '$ref' => '#/components/schemas/BrowserMembershipContextRequiredError',
                ],
                [
                    '$ref' => '#/components/schemas/BrowserMembershipContextDeniedError',
                ],
                [
                    '$ref' => '#/components/schemas/BrowserSessionContextMismatchError',
                ],
                [
                    '$ref' => '#/components/schemas/OrganizationalContextRequiredError',
                ],
                [
                    '$ref' => '#/components/schemas/OrganizationalContextDeniedError',
                ],
            ],
            $forbiddenSchemas,
        );

        $validationSchemas =
            $operation['responses']['422']['content']['application/json']['schema']['oneOf']
            ?? [];

        $this->assertSame(
            [
                [
                    '$ref' => '#/components/schemas/InvalidBrowserMembershipIdError',
                ],
                [
                    '$ref' => '#/components/schemas/InvalidOrganizationalAssignmentIdError',
                ],
            ],
            $validationSchemas,
        );

        $serverSchemas =
            $operation['responses']['500']['content']['application/json']['schema']['oneOf']
            ?? [];

        $this->assertSame(
            [
                [
                    '$ref' => '#/components/schemas/OrganizationalContextResolutionFailedError',
                ],
                [
                    '$ref' => '#/components/schemas/InternalServerError',
                ],
            ],
            $serverSchemas,
        );

        $this->assertSame(
            '#/components/responses/BrowserSessionUnavailable',
            $operation['responses']['503']['$ref']
                ?? null,
        );
    }

    public function test_global_tenant_management_error_boundaries_are_exact(): void
    {
        foreach (
            [
                'GET /api/v1/core/tenants',
                'POST /api/v1/core/tenants',
                'PUT /api/v1/core/tenants/{id}',
            ] as $operationKey
        ) {
            $operation = $this->operation(
                $operationKey,
            );

            $this->assertSame(
                '#/components/responses/AuthenticationRequired',
                $operation['responses']['401']['$ref']
                    ?? null,
            );

            $this->assertSame(
                '#/components/responses/AuthorizationDenied',
                $operation['responses']['403']['$ref']
                    ?? null,
            );

            $this->assertSame(
                '#/components/responses/ValidationFailed',
                $operation['responses']['422']['$ref']
                    ?? null,
            );

            $this->assertSame(
                '#/components/responses/InternalServerError',
                $operation['responses']['500']['$ref']
                    ?? null,
            );
        }

        $this->assertSame(
            '#/components/responses/ResourceNotFound',
            $this->operation(
                'PUT /api/v1/core/tenants/{id}',
            )['responses']['404']['$ref']
                ?? null,
        );
    }

    public function test_membership_operations_preserve_current_http_identifier_semantics(): void
    {
        $spec = $this->spec();

        $this->assertSame(
            '#/components/schemas/MembershipPathIdentifier',
            $spec['components']['parameters']['MembershipId']['schema']['$ref']
                ?? null,
        );

        $this->assertSame(
            '#/components/schemas/MembershipPathIdentifier',
            $spec['components']['parameters']['TargetMembershipId']['schema']['$ref']
                ?? null,
        );

        $this->assertSame(
            '#/components/responses/MembershipSwitchDenied',
            $this->operation(
                'POST /api/v1/user/memberships/{membership_id}/switch',
            )['responses']['403']['$ref']
                ?? null,
        );

        $this->assertSame(
            '#/components/responses/MembershipRoleAssignmentRejected',
            $this->operation(
                'POST /api/v1/user/memberships/{target_membership_id}/assign-role',
            )['responses']['404']['$ref']
                ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(
        string $operationKey,
    ): array {
        [$method, $path] = explode(
            ' ',
            $operationKey,
            2,
        );

        $operation =
            $this->spec()['paths'][$path][strtolower($method)]
            ?? null;

        $this->assertIsArray(
            $operation,
            sprintf(
                'Missing OpenAPI operation [%s].',
                $operationKey,
            ),
        );

        return $operation;
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
