<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveEntitlementPolicy;

final class StoreLeaveEntitlementPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['organization_id', 'organization_unit_id', 'employment_type_id', 'employment_classification_id', 'effective_to'] as $field) {
            $value = $this->input($field);

            $this->merge([
                $field => is_string($value) && trim($value) !== '' ? trim($value) : null,
            ]);
        }
    }

    /**
     * Catatan: rule di sini hanya memvalidasi bentuk input. Aturan
     * bisnis (LeaveType harus BALANCE-backed, organization_unit_id
     * butuh organization_id) ditegakkan
     * LeaveEntitlementPolicyService::createPolicy() sebagai domain
     * conflict (409), bukan 422.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get('authenticated_tenant_id');
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'leave_type_id' => [
                'required',
                'uuid',
                Rule::exists('leave_types', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'organization_id' => [
                'nullable',
                'uuid',
                Rule::exists('organizations', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'organization_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'employment_type_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_types', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'employment_classification_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_classifications', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'period_basis' => [
                'required',
                'string',
                Rule::in([
                    LeaveEntitlementPolicy::PERIOD_BASIS_CALENDAR_YEAR,
                    LeaveEntitlementPolicy::PERIOD_BASIS_EMPLOYMENT_ANNIVERSARY,
                    LeaveEntitlementPolicy::PERIOD_BASIS_MANUAL,
                ]),
            ],
            'grant_units' => [
                'required',
                'numeric',
                'gt:0',
            ],
            'carryover_mode' => [
                'sometimes',
                'string',
                Rule::in([
                    LeaveEntitlementPolicy::CARRYOVER_NONE,
                    LeaveEntitlementPolicy::CARRYOVER_LIMITED,
                ]),
            ],
            'carryover_limit_units' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'effective_from' => [
                'required',
                'date',
            ],
            'effective_to' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],
            'priority' => [
                'sometimes',
                'integer',
            ],
        ];
    }
}
