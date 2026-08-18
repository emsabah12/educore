<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class BrowserOpenApiContractTest extends TestCase
{
    public function test_browser_session_security_scheme_is_cookie_based_and_separate_from_bearer_auth(): void
    {
        $schemes = $this->spec()['components']['securitySchemes']
            ?? [];

        $this->assertSame(
            [
                'type' => 'apiKey',
                'in' => 'cookie',
                'name' => '__Host-educore-session',
            ],
            array_intersect_key(
                $schemes['BrowserSessionAuth'] ?? [],
                array_flip([
                    'type',
                    'in',
                    'name',
                ]),
            ),
        );

        $this->assertSame(
            'bearer',
            $schemes['BearerAuth']['scheme']
                ?? null,
        );
    }

    public function test_browser_operations_are_all_documented_with_canonical_route_names(): void
    {
        $spec = $this->spec();

        $expected = [
            'GET /api/v1/browser/session/csrf' => 'api.v1.browser.session.csrf',
            'POST /api/v1/browser/auth/login' => 'api.v1.browser.auth.login',
            'GET /api/v1/browser/auth/me' => 'api.v1.browser.auth.me',
            'POST /api/v1/browser/auth/logout' => 'api.v1.browser.auth.logout',
            'POST /api/v1/browser/user/memberships/{membership_id}/switch' => 'api.v1.browser.user.memberships.switch',
        ];

        foreach ($expected as $operationKey => $routeName) {
            [$method, $path] = explode(
                ' ',
                $operationKey,
                2,
            );

            $operation = $spec['paths'][$path][strtolower($method)]
                ?? null;

            $this->assertIsArray(
                $operation,
                sprintf(
                    'Missing Browser BFF OpenAPI operation [%s].',
                    $operationKey,
                ),
            );

            $this->assertSame(
                $routeName,
                $operation['x-laravel-route-name']
                    ?? null,
            );
        }
    }

    public function test_browser_state_changing_operations_explicitly_record_request_forgery_protection(): void
    {
        $spec = $this->spec();

        foreach (
            [
                '/api/v1/browser/auth/login',
                '/api/v1/browser/auth/logout',
                '/api/v1/browser/user/memberships/{membership_id}/switch',
            ] as $path
        ) {
            $this->assertTrue(
                (bool) (
                    $spec['paths'][$path]['post']['x-educore-request-forgery-protected']
                    ?? false
                ),
                sprintf(
                    'Browser mutation [%s] must record request-forgery protection.',
                    $path,
                ),
            );
        }
    }

    public function test_browser_error_components_lock_stable_machine_codes(): void
    {
        $schemas = $this->spec()['components']['schemas']
            ?? [];

        $expected = [
            'BrowserSessionAuthenticationRequiredError' => 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            'BrowserMembershipContextRequiredError' => 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
            'InvalidBrowserMembershipIdError' => 'INVALID_BROWSER_MEMBERSHIP_ID',
            'BrowserMembershipContextDeniedError' => 'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
            'BrowserSessionContextMismatchError' => 'BROWSER_SESSION_CONTEXT_MISMATCH',
            'BrowserSessionUnavailableError' => 'BROWSER_SESSION_UNAVAILABLE',
        ];

        foreach ($expected as $schemaName => $code) {
            $this->assertSame(
                $code,
                $schemas[$schemaName]['allOf'][1]['properties']['code']['const']
                    ?? null,
                sprintf(
                    'Browser error code drift detected for [%s].',
                    $schemaName,
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $parsed = Yaml::parseFile(
            base_path('docs/api/openapi.yaml'),
        );

        $this->assertIsArray($parsed);

        return $parsed;
    }
}
