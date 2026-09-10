<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrganizationUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `code` sengaja unik PER ORGANIZATION (bukan per-tenant seperti
     * `Organization::code`, dan bukan pula global) — dua Organization
     * berbeda milik tenant yang sama boleh punya Unit dengan kode
     * identik (mis. dua kampus yang sama-sama punya unit "REKTORAT").
     * `tenant_id` dan `organization` diambil dari context/route yang
     * sudah diverifikasi controller, bukan dari payload.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        $organizationId = $this->route('organization');
        $organizationId = is_string($organizationId) ? $organizationId : '';

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
                Rule::unique('organization_units', 'code')
                    ->where(
                        fn($query) => $query
                            ->where(
                                'tenant_id',
                                $tenantId,
                            )
                            ->where(
                                'organization_id',
                                $organizationId,
                            )
                            ->whereNull('deleted_at'),
                    ),
            ],
        ];
    }
}
