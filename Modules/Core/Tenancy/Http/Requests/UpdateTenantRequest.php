<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateTenantRequest extends FormRequest
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
     * Masukkan route parameter ke validation payload dan
     * normalisasi nama tanpa mengubah nilai boolean.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        $normalized = [
            'id' => $this->route('id'),
        ];

        if (
            array_key_exists('name', $input)
            && is_string($input['name'])
        ) {
            $normalized['name'] = trim(
                $input['name'],
            );
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id' => [
                'required',
                'uuid:7',
            ],
            'name' => [
                'sometimes',
                'string',
                'min:3',
                'max:255',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Minimal satu atribut bisnis harus diberikan.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $input = $this->all();

                $hasName = array_key_exists(
                    'name',
                    $input,
                );

                $hasActiveStatus = array_key_exists(
                    'is_active',
                    $input,
                );

                if (! $hasName && ! $hasActiveStatus) {
                    $validator->errors()->add(
                        'payload',
                        'At least one of name or is_active must be provided.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' =>
            'The tenant id is required.',
            'id.uuid' =>
            'The tenant id must be a valid UUIDv7.',
            'name.string' =>
            'The tenant name must be a string.',
            'name.min' =>
            'The tenant name must contain at least 3 characters.',
            'name.max' =>
            'The tenant name may not exceed 255 characters.',
            'is_active.boolean' =>
            'The tenant active status must be boolean.',
        ];
    }
}
