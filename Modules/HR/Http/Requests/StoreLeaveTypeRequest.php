<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveType;

final class StoreLeaveTypeRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('leave_types', 'code')
                    ->where(
                        static fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'name' => [
                'required',
                'string',
                'max:120',
            ],
            'category' => [
                'required',
                'string',
                Rule::in([LeaveType::CATEGORY_LEAVE, LeaveType::CATEGORY_PERMIT]),
            ],
            'balance_mode' => [
                'required',
                'string',
                Rule::in([LeaveType::BALANCE_MODE_BALANCE, LeaveType::BALANCE_MODE_NONE]),
            ],
            'unit' => [
                'required',
                'string',
                Rule::in([LeaveType::UNIT_DAY, LeaveType::UNIT_HOUR]),
            ],
            'description' => [
                'nullable',
                'string',
            ],
        ];
    }
}
