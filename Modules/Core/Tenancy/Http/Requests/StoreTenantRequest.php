<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRequest extends FormRequest
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
     * Normalisasi harus dilakukan sebelum unique validation.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $normalized = [];

        if (
            array_key_exists('name', $input)
            && is_string($input['name'])
        ) {
            $normalized['name'] = trim(
                $input['name'],
            );
        }

        if (
            array_key_exists('subdomain', $input)
            && is_string($input['subdomain'])
        ) {
            $normalized['subdomain'] = strtolower(
                trim($input['subdomain']),
            );
        }

        if (
            array_key_exists('initial_admin_user_id', $input)
            && is_string($input['initial_admin_user_id'])
        ) {
            $normalized['initial_admin_user_id'] = trim(
                $input['initial_admin_user_id'],
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'subdomain' => [
                'required',
                'string',
                'max:50',

                /*
                 * Format DNS label sederhana:
                 *
                 * - hanya a-z, 0-9, dan tanda hubung;
                 * - harus dimulai dan diakhiri alphanumeric;
                 * - tidak menerima underscore;
                 * - lowercase sudah dibentuk di prepareForValidation().
                 */
                'regex:/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/',

                /*
                 * Jangan memakai withoutTrashed().
                 *
                 * Database memiliki unique index global pada subdomain,
                 * sehingga subdomain soft-deleted tetap dicadangkan.
                 */
                Rule::unique(
                    'tenants',
                    'subdomain',
                ),
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'initial_admin_user_id' => [
                'required',
                'string',
                'uuid:7',
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
            'The tenant name is required.',
            'name.string' =>
            'The tenant name must be a string.',
            'name.min' =>
            'The tenant name must contain at least 3 characters.',
            'name.max' =>
            'The tenant name may not exceed 255 characters.',

            'subdomain.required' =>
            'The tenant subdomain is required.',
            'subdomain.string' =>
            'The tenant subdomain must be a string.',
            'subdomain.max' =>
            'The tenant subdomain may not exceed 50 characters.',
            'subdomain.regex' =>
            'The tenant subdomain may only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique' =>
            'The tenant subdomain has already been registered.',

            'is_active.boolean' =>
            'The tenant active status must be boolean.',

            'initial_admin_user_id.required' =>
            'The initial admin user id is required.',
            'initial_admin_user_id.string' =>
            'The initial admin user id must be a string.',
            'initial_admin_user_id.uuid' =>
            'The initial admin user id must be a valid UUIDv7.',
        ];
    }
}
