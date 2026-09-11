<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLeaveRequestRequest extends FormRequest
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
            'employment_id' => [
                'required',
                'uuid',
                Rule::exists('employments', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'leave_type_id' => [
                'required',
                'uuid',
                Rule::exists('leave_types', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'starts_at' => [
                'required',
                'date',
            ],
            'ends_at' => [
                'required',
                'date',
                'after:starts_at',
            ],
            'request_timezone' => [
                'required',
                'string',
                'max:64',
            ],
            'requested_units' => [
                'required',
                'numeric',
                'gt:0',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
