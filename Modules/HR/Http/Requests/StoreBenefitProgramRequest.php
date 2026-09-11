<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\BenefitProgram;

final class StoreBenefitProgramRequest extends FormRequest
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
                'max:60',
                Rule::unique('benefit_programs', 'code')
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
                'max:150',
            ],
            'category' => [
                'required',
                'string',
                Rule::in([
                    BenefitProgram::CATEGORY_STATUTORY,
                    BenefitProgram::CATEGORY_GOVERNMENT,
                    BenefitProgram::CATEGORY_INSTITUTIONAL,
                    BenefitProgram::CATEGORY_OTHER,
                ]),
            ],
            'beneficiary_scope' => [
                'required',
                'string',
                Rule::in([
                    BenefitProgram::BENEFICIARY_SCOPE_EMPLOYEE,
                    BenefitProgram::BENEFICIARY_SCOPE_DEPENDENT,
                    BenefitProgram::BENEFICIARY_SCOPE_EITHER,
                ]),
            ],
            'payroll_relevance' => [
                'required',
                'string',
                Rule::in([
                    BenefitProgram::PAYROLL_RELEVANCE_NONE,
                    BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
                    BenefitProgram::PAYROLL_RELEVANCE_EXTERNAL_PAYMENT_TRACKING,
                ]),
            ],
            'description' => [
                'nullable',
                'string',
            ],
        ];
    }
}
