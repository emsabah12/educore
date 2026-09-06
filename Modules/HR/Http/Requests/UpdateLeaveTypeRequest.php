<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveType;

/**
 * Aturan bentuk-input di sini murni sintaksis (enum valid, panjang
 * string). Aturan bisnis "field beku setelah dirujuk" (§15.1) DITEGAKKAN
 * oleh LeaveTypeService::updateType(), dilaporkan sebagai domain
 * conflict (409), bukan 422 — pola yang sama seperti FormRequest HR-003
 * lainnya.
 */
final class UpdateLeaveTypeRequest extends FormRequest
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
        $leaveTypeId = $this->route('leaveTypeId');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('leave_types', 'code')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    )
                    ->ignore($leaveTypeId),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:120',
            ],
            'category' => [
                'sometimes',
                'string',
                Rule::in([LeaveType::CATEGORY_LEAVE, LeaveType::CATEGORY_PERMIT]),
            ],
            'balance_mode' => [
                'sometimes',
                'string',
                Rule::in([LeaveType::BALANCE_MODE_BALANCE, LeaveType::BALANCE_MODE_NONE]),
            ],
            'unit' => [
                'sometimes',
                'string',
                Rule::in([LeaveType::UNIT_DAY, LeaveType::UNIT_HOUR]),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }
}
