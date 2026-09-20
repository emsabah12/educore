<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §Pendaftaran tenant mandiri (self-service) — validasi ini SENGAJA
 * mirip persis dengan `StoreTenantWithNewAdminRequest` (superadmin),
 * tapi TANPA field `is_active` sama sekali: tenant hasil pendaftaran
 * mandiri SELALU langsung aktif (keputusan produk — tidak ada gerbang
 * approval manual), tidak seperti jalur superadmin yang boleh
 * membuat tenant non-aktif untuk staging.
 *
 * `authorize()` SENGAJA selalu true — endpoint ini memang PUBLIK
 * (tidak butuh autentikasi apa pun), reachable oleh siapa saja yang
 * ingin mendaftarkan sekolah/institusi barunya sendiri.
 */
final class RegisterTenantRequest extends FormRequest
{
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
            'name.required' => 'The school/institution name is required.',
            'subdomain.required' => 'The subdomain is required.',
            'subdomain.regex' => 'The subdomain may only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique' => 'The subdomain has already been registered.',
            'admin_name.required' => 'Your name is required.',
            'admin_email.required' => 'Your email is required.',
            'admin_email.email' => 'Your email must be a valid email address.',
            'admin_email.unique' => 'This email has already been registered.',
            'admin_password.required' => 'A password is required.',
            'admin_password.min' => 'The password must contain at least 8 characters.',
        ];
    }
}
