<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiValidationErrorContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post(
            '/api/test/validation-contract',
            static function (
                ApiValidationContractRequest $request,
            ): JsonResponse {
                return response()->json([
                    'status' => 'success',
                    'data' => $request->validated(),
                ]);
            },
        );
    }

    public function test_api_form_request_validation_uses_canonical_error_envelope(): void
    {
        $this
            ->postJson(
                '/api/test/validation-contract',
                [],
            )
            ->assertUnprocessable()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'message' =>
                'The submitted data is invalid.',
                'errors' => [
                    'name' => [
                        'The contract name is required.',
                    ],
                ],
            ]);
    }

    public function test_validation_preserves_field_specific_messages(): void
    {
        $this
            ->postJson(
                '/api/test/validation-contract',
                [
                    'name' => 'x',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            )
            ->assertJsonPath(
                'message',
                'The submitted data is invalid.',
            )
            ->assertJsonPath(
                'errors.name.0',
                'The contract name must contain at least 3 characters.',
            );
    }

    public function test_valid_request_is_not_affected_by_validation_renderer(): void
    {
        $this
            ->postJson(
                '/api/test/validation-contract',
                [
                    'name' => 'Valid Contract',
                ],
            )
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'name' => 'Valid Contract',
                ],
            ]);
    }
}

final class ApiValidationContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' =>
            'The contract name is required.',
            'name.string' =>
            'The contract name must be a string.',
            'name.min' =>
            'The contract name must contain at least 3 characters.',
        ];
    }
}
