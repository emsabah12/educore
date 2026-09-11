<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get('authenticated_tenant_id');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9](?:[a-z0-9._-]{0,148}[a-z0-9])?$/',
                Rule::unique('roles', 'name')
                    ->where(
                        static fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            is_string($tenantId) ? $tenantId : '',
                        ),
                    ),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Nama role hanya boleh huruf kecil, angka, titik, dan tanda hubung.',
        ];
    }
}
