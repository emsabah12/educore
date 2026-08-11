<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignMembershipRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Authorization actor dilakukan oleh:
         *
         * InjectTenantContext
         *      ↓
         * tenant.role:admin
         *
         * Request hanya bertanggung jawab pada input validation.
         */
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'string',
                'uuid:7',
                'exists:roles,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_id.required' => 'Atribut role_id wajib disertakan.',
            'role_id.string' => 'Atribut role_id wajib berupa string.',
            'role_id.uuid' => 'Format role_id wajib berupa UUIDv7 yang valid.',
            'role_id.exists' => 'Role yang dipilih tidak ditemukan.',
        ];
    }
}
