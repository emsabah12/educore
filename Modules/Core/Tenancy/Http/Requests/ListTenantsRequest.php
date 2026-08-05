<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListTenantsRequest extends FormRequest
{
    /**
     * Authorization dilakukan oleh route middleware:
     *
     * InjectAuthenticatedUser
     * → RequireGlobalSuperadmin
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi query parameter sebelum validation.
     */
    protected function prepareForValidation(): void
    {
        $perPage = $this->query('per_page');

        if (is_string($perPage)) {
            $this->merge([
                'per_page' => trim($perPage),
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' =>
            'The per page value must be an integer.',
            'per_page.min' =>
            'The per page value must be at least 1.',
            'per_page.max' =>
            'The per page value may not exceed 100.',
        ];
    }
}
