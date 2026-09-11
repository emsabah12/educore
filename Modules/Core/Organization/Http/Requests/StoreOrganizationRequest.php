<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `code` sengaja unik PER TENANT (bukan global) — sama seperti
     * `EmploymentType::code`. `tenant_id` diambil dari context yang
     * sudah diverifikasi middleware, bukan dari payload.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('organizations', 'code')
                    ->where(
                        fn ($query) => $query->where(
                            'tenant_id',
                            $tenantId,
                        )->whereNull('deleted_at'),
                    ),
            ],
        ];
    }
}
