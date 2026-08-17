<?php

declare(strict_types=1);

namespace Modules\Core\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public static function make(
        string $code,
        string $message,
        int $status,
        ?array $errors = null,
    ): JsonResponse {
        $code = trim($code);
        $message = trim($message);

        if ($code === '') {
            $code = 'INTERNAL_SERVER_ERROR';
        }

        if ($message === '') {
            $message = 'An unexpected error occurred.';
        }

        $payload = [
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== null && $errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json(
            $payload,
            $status,
        );
    }
}
