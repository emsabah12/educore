<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §15.5 — "Status is never accepted as a client-controlled PATCH
 * field." `status` sengaja TIDAK ADA di daftar rule sama sekali — kalau
 * client mengirimnya, field itu diam-diam diabaikan (bukan divalidasi
 * lalu ditolak), karena `validated()` di controller hanya mengambil
 * field yang memang terdaftar di rules().
 */
final class UpdateLeaveRequestRequest extends FormRequest
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
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'leave_type_id' => [
                'sometimes',
                'uuid',
                Rule::exists('leave_types', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'starts_at' => [
                'sometimes',
                'date',
            ],
            'ends_at' => [
                'sometimes',
                'date',
                'after:starts_at',
            ],
            'request_timezone' => [
                'sometimes',
                'string',
                'max:64',
            ],
            'requested_units' => [
                'sometimes',
                'numeric',
                'gt:0',
            ],
            'reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
