<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveApprovalPolicyStep;

/**
 * §15.4 hanya mendaftarkan 4 endpoint (tanpa endpoint terpisah untuk
 * step) — jadi `steps` diterima sebagai array bersarang di body
 * `POST /leave-approval-policies`, bukan lewat endpoint tersendiri.
 */
final class StoreLeaveApprovalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['leave_type_id', 'organization_id', 'organization_unit_id', 'employment_type_id', 'employment_classification_id', 'effective_to'] as $field) {
            $value = $this->input($field);

            $this->merge([
                $field => is_string($value) && trim($value) !== '' ? trim($value) : null,
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get('authenticated_tenant_id');
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'policy_code' => [
                'required',
                'string',
                'max:50',
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'leave_type_id' => [
                'nullable',
                'uuid',
                Rule::exists('leave_types', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'organization_id' => [
                'nullable',
                'uuid',
                Rule::exists('organizations', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'organization_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'employment_type_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_types', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'employment_classification_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_classifications', 'id')
                    ->where(static fn(Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'decision_mode' => [
                'required',
                'string',
                Rule::in([
                    LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
                    LeaveApprovalPolicy::DECISION_MODE_AUTO,
                ]),
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
            'steps' => [
                'sometimes',
                'array',
            ],
            'steps.*.step_order' => [
                'required_with:steps',
                'integer',
                'min:1',
            ],
            'steps.*.required_permission' => [
                'required_with:steps',
                'string',
                'max:120',
            ],
            'steps.*.scope_strategy' => [
                'required_with:steps',
                'string',
                Rule::in([
                    LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
                    LeaveApprovalPolicyStep::SCOPE_ORGANIZATION,
                    LeaveApprovalPolicyStep::SCOPE_TENANT,
                ]),
            ],
            'steps.*.independent_approver' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
