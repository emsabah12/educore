<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Menutup celah operasional §"provisioning admin baru" — validasi ini
 * SENGAJA tidak menerima `initial_admin_user_id` sama sekali. Admin baru
 * dibuat dari nol (nama, email, password) dalam satu operasi atomik lewat
 * `TenantProvisioningService::provisionWithNewAdmin()`.
 */
final class StoreTenantWithNewAdminRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $normalized = [];

        foreach (['name', 'subdomain', 'admin_name'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $normalized[$field] = trim($input[$field]);
            }
        }

        if ($this->has('subdomain') && is_string($input['subdomain'] ?? null)) {
            $normalized['subdomain'] = strtolower($normalized['subdomain'] ?? trim($input['subdomain']));
        }

        if ($this->has('admin_email') && is_string($input['admin_email'] ?? null)) {
            $normalized['admin_email'] = strtolower(trim($input['admin_email']));
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
                'regex:/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/',
                Rule::unique('tenants', 'subdomain'),
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'admin_name' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'admin_email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'admin_password' => [
                'required',
                'string',
                'min:8',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The tenant name is required.',
            'subdomain.required' => 'The tenant subdomain is required.',
            'subdomain.regex' => 'The tenant subdomain may only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique' => 'The tenant subdomain has already been registered.',
            'admin_name.required' => 'The initial admin name is required.',
            'admin_email.required' => 'The initial admin email is required.',
            'admin_email.email' => 'The initial admin email must be a valid email address.',
            'admin_email.unique' => 'The initial admin email has already been registered.',
            'admin_password.required' => 'The initial admin password is required.',
            'admin_password.min' => 'The initial admin password must contain at least 8 characters.',
        ];
    }
}
