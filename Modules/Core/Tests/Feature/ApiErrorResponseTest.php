<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ApiErrorResponseTest extends TestCase
{
    public function test_it_builds_canonical_error_envelope(): void
    {
        $response = ApiErrorResponse::make(
            code: 'AUTHORIZATION_DENIED',
            message: 'You are not allowed to perform this operation.',
            status: Response::HTTP_FORBIDDEN,
        );

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'AUTHORIZATION_DENIED',
                'message' =>
                'You are not allowed to perform this operation.',
            ],
            $response->getData(true),
        );
    }

    public function test_it_supports_validation_errors(): void
    {
        $response = ApiErrorResponse::make(
            code: 'VALIDATION_FAILED',
            message: 'The submitted data is invalid.',
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            errors: [
                'email' => [
                    'The email field is required.',
                ],
            ],
        );

        $this->assertSame(
            [
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'message' => 'The submitted data is invalid.',
                'errors' => [
                    'email' => [
                        'The email field is required.',
                    ],
                ],
            ],
            $response->getData(true),
        );
    }

    public function test_it_does_not_include_empty_errors_collection(): void
    {
        $response = ApiErrorResponse::make(
            code: 'RESOURCE_NOT_FOUND',
            message: 'The requested resource was not found.',
            status: Response::HTTP_NOT_FOUND,
            errors: [],
        );

        $payload = $response->getData(true);

        $this->assertArrayNotHasKey(
            'errors',
            $payload,
        );
    }

    public function test_it_fails_safe_when_code_or_message_is_empty(): void
    {
        $response = ApiErrorResponse::make(
            code: ' ',
            message: ' ',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
        );

        $this->assertSame(
            'INTERNAL_SERVER_ERROR',
            $response->getData(true)['code'],
        );

        $this->assertSame(
            'An unexpected error occurred.',
            $response->getData(true)['message'],
        );
    }
}
