<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantRolePermissionsRequest extends FormRequest
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
        return [
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['uuid', Rule::exists('permissions', 'id')],
        ];
    }
}
