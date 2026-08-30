<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Http\Requests\LoginTokenRequest;
use Tests\TestCase;

final class LoginTokenRequestContractTest extends TestCase
{
    public function test_login_request_declares_identifier_as_required_string(): void
    {
        $rules = (new LoginTokenRequest())->rules();

        $this->assertArrayHasKey(
            'identifier',
            $rules,
            'Canonical global login must expose identifier as a validation field.',
        );

        $this->assertContains(
            'required',
            $rules['identifier'],
        );

        $this->assertContains(
            'string',
            $rules['identifier'],
        );
    }

    public function test_login_request_does_not_require_legacy_tenant_aware_fields(): void
    {
        $rules = (new LoginTokenRequest())->rules();

        $legacyCredentialFields = array_values(
            array_intersect(
                [
                    'email',
                    'tenant_uuid',
                    'tenant_id',
                    'membership_id',
                ],
                array_keys($rules),
            ),
        );

        $this->assertSame(
            [],
            $legacyCredentialFields,
            'Global login validation must not treat Tenant or Membership context as credentials.',
        );
    }

    public function test_identifier_and_password_validate_without_tenant_context(): void
    {
        $validated = $this->validateRequest([
            'identifier' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame(
            'user@example.com',
            $validated['identifier'] ?? null,
        );

        $this->assertSame(
            'secret123',
            $validated['password'] ?? null,
        );

        $this->assertArrayNotHasKey(
            'tenant_uuid',
            $validated,
        );

        $this->assertArrayNotHasKey(
            'tenant_id',
            $validated,
        );

        $this->assertArrayNotHasKey(
            'membership_id',
            $validated,
        );
    }

    public function test_email_identifier_is_trimmed_and_lowercased_before_validation(): void
    {
        $validated = $this->validateRequest([
            'identifier' => '  USER@Example.COM  ',
            'password' => 'secret123',
        ]);

        $this->assertSame(
            'user@example.com',
            $validated['identifier'] ?? null,
        );
    }

    public function test_legacy_email_and_tenant_shape_cannot_replace_identifier(): void
    {
        try {
            $this->validateRequest([
                'email' => 'user@example.com',
                'password' => 'secret123',
                'tenant_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            ]);

            $this->fail(
                'Legacy email + tenant_uuid login shape unexpectedly passed validation.',
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'identifier',
                $exception->errors(),
                'Canonical login must require identifier even when legacy fields are supplied.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateRequest(array $input): array
    {
        $request = LoginTokenRequest::create(
            '/api/v1/auth/login-token',
            'POST',
            $input,
        );

        $request->headers->set(
            'Accept',
            'application/json',
        );

        $request->setContainer(
            $this->app,
        );

        $request->setRedirector(
            $this->app->make(
                Redirector::class,
            ),
        );

        $request->validateResolved();

        return $request->validated();
    }
}
