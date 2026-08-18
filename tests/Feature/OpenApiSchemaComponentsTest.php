<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiSchemaComponentsTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_SCHEMAS = [
        'Uuid',
        'UuidV7',
        'MembershipPathIdentifier',
        'LoginTokenRequest',
        'LoginTokenSuccess',
        'LogoutSuccess',
        'BrowserLoginData',
        'BrowserLoginSuccess',
        'BrowserLogoutSuccess',
        'BrowserMembershipSwitchData',
        'BrowserMembershipSwitchSuccess',
        'BrowserSessionAuthenticationRequiredError',
        'BrowserMembershipContextRequiredError',
        'InvalidBrowserMembershipIdError',
        'BrowserMembershipContextDeniedError',
        'BrowserSessionContextMismatchError',
        'BrowserSessionUnavailableError',
        'AuthenticatedBootstrapSuccess',
        'TenantCapabilitySuccess',
        'WorkspaceCapabilitySuccess',
        'RoleCatalogSuccess',
        'HealthStatus',
        'NotificationDispatchRequest',
        'NotificationDispatchSuccess',
        'TenantListItem',
        'TenantListSuccess',
        'StoreTenantRequest',
        'UpdateTenantRequest',
        'TenantResource',
        'InitialTenantAdmin',
        'TenantCreatedSuccess',
        'TenantUpdatedSuccess',
        'MembershipSummary',
        'MembershipListSuccess',
        'MembershipSwitchSuccess',
        'WorkspaceSummary',
        'WorkspaceDiscoverySuccess',
        'MembershipRoleAssignmentRequest',
        'MembershipRoleAssignmentSuccess',
        'ApiError',
        'ValidationError',
    ];

    public function test_exact_foundation_schema_components_are_declared(): void
    {
        $schemas = $this->schemas();

        foreach (self::REQUIRED_SCHEMAS as $schemaName) {
            $this->assertArrayHasKey(
                $schemaName,
                $schemas,
                sprintf(
                    'Missing OpenAPI schema component [%s].',
                    $schemaName,
                ),
            );
        }
    }

    public function test_login_tenant_identifier_is_generic_uuid_not_uuid_v7(): void
    {
        $schemas = $this->schemas();

        $this->assertSame(
            '#/components/schemas/Uuid',
            $schemas['LoginTokenRequest']['properties']['tenant_uuid']['$ref']
                ?? null,
        );

        $this->assertSame(
            'uuid',
            $schemas['Uuid']['format']
                ?? null,
        );

        $this->assertArrayNotHasKey(
            'pattern',
            $schemas['Uuid'],
        );
    }

    public function test_membership_path_identifiers_do_not_claim_uuid_v7_validation(): void
    {
        $spec = $this->spec();

        $membershipPathSchema =
            $spec['components']['schemas']['MembershipPathIdentifier']
            ?? [];

        $this->assertSame(
            'string',
            $membershipPathSchema['type']
                ?? null,
        );

        $this->assertArrayNotHasKey(
            'format',
            $membershipPathSchema,
        );

        $this->assertArrayNotHasKey(
            'pattern',
            $membershipPathSchema,
        );

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
    }

    public function test_workspace_discovery_and_capability_scope_keep_distinct_type_vocabularies(): void
    {
        $schemas = $this->schemas();

        $this->assertSame(
            [
                'organization',
                'organization_unit',
            ],
            $schemas['WorkspaceCapabilityScope']['properties']['type']['enum']
                ?? null,
        );

        $this->assertSame(
            'TENANT',
            $schemas['TenantWorkspaceSummary']['properties']['type']['const']
                ?? null,
        );

        $this->assertSame(
            'ORGANIZATION',
            $schemas['OrganizationWorkspaceSummary']['properties']['type']['const']
                ?? null,
        );

        $this->assertSame(
            'ORGANIZATION_UNIT',
            $schemas['OrganizationUnitWorkspaceSummary']['properties']['type']['const']
                ?? null,
        );
    }

    public function test_browser_success_schemas_never_expose_bearer_credentials(): void
    {
        $schemas = $this->schemas();

        foreach (
            [
                'BrowserLoginData',
                'BrowserMembershipSwitchData',
            ] as $schemaName
        ) {
            $properties = $schemas[$schemaName]['properties']
                ?? [];

            $this->assertArrayNotHasKey(
                'access_token',
                $properties,
                sprintf(
                    'Browser-safe schema [%s] must not expose access_token.',
                    $schemaName,
                ),
            );

            $this->assertArrayNotHasKey(
                'token_type',
                $properties,
            );
        }
    }

    public function test_browser_membership_locators_are_uuid_v7_and_never_authority_claims(): void
    {
        $spec = $this->spec();

        foreach (
            [
                'BrowserMembershipLocator',
                'BrowserMembershipPathId',
            ] as $parameterName
        ) {
            $this->assertSame(
                '#/components/schemas/UuidV7',
                $spec['components']['parameters'][$parameterName]['schema']['$ref']
                    ?? null,
            );
        }

        $this->assertSame(
            'X-EduCore-Membership-Id',
            $spec['components']['parameters']['BrowserMembershipLocator']['name']
                ?? null,
        );
    }

    public function test_health_schema_matches_system_health_service_projection(): void
    {
        $schemas = $this->schemas();

        $this->assertSame(
            [
                'UP',
                'DOWN',
            ],
            $schemas['HealthStatus']['properties']['status']['enum']
                ?? null,
        );

        $this->assertSame(
            [
                'database',
                'storage',
            ],
            $schemas['HealthComponents']['required']
                ?? null,
        );

        $this->assertSame(
            [
                'healthy',
                'message',
            ],
            $schemas['HealthComponent']['required']
                ?? null,
        );
    }

    public function test_tenant_list_and_tenant_resource_are_not_conflated(): void
    {
        $schemas = $this->schemas();

        $listRequired =
            $schemas['TenantListItem']['required']
            ?? [];

        $resourceRequired =
            $schemas['TenantResource']['required']
            ?? [];

        $this->assertContains(
            'created_at',
            $listRequired,
        );

        $this->assertNotContains(
            'settings',
            $listRequired,
        );

        $this->assertNotContains(
            'updated_at',
            $listRequired,
        );

        $this->assertContains(
            'settings',
            $resourceRequired,
        );

        $this->assertContains(
            'updated_at',
            $resourceRequired,
        );

        $this->assertContains(
            'deleted_at',
            $resourceRequired,
        );
    }

    public function test_initial_tenant_admin_projection_contains_all_canonical_identifiers(): void
    {
        $schemas = $this->schemas();

        $this->assertSame(
            [
                'user_id',
                'person_id',
                'membership_id',
            ],
            $schemas['InitialTenantAdmin']['required']
                ?? null,
        );
    }

    public function test_notification_options_reject_unknown_client_controlled_fields_in_contract(): void
    {
        $schemas = $this->schemas();

        $this->assertFalse(
            $schemas['NotificationOptions']['additionalProperties']
                ?? true,
        );

        $this->assertArrayHasKey(
            'title',
            $schemas['NotificationOptions']['properties']
                ?? [],
        );
    }

    public function test_validation_error_locks_canonical_machine_code_and_message(): void
    {
        $schemas = $this->schemas();

        $this->assertSame(
            'VALIDATION_FAILED',
            $schemas['ValidationError']['properties']['code']['const']
                ?? null,
        );

        $this->assertSame(
            'The submitted data is invalid.',
            $schemas['ValidationError']['properties']['message']['const']
                ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return $this->spec()['components']['schemas']
            ?? [];
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
