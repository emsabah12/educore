<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\CompensationComponent;

final class StoreCompensationComponentRequest extends FormRequest
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
                Rule::unique('compensation_components', 'code')
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
                    CompensationComponent::CATEGORY_BASE_PAY,
                    CompensationComponent::CATEGORY_ALLOWANCE,
                    CompensationComponent::CATEGORY_RATE,
                    CompensationComponent::CATEGORY_OTHER_EARNING_INPUT,
                ]),
            ],
            'value_mode' => [
                'required',
                'string',
                Rule::in([
                    CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
                    CompensationComponent::VALUE_MODE_RATE_PER_UNIT,
                ]),
            ],
            // §7.2 — unit_code WAJIB untuk RATE_PER_UNIT, WAJIB kosong
            // untuk FIXED_AMOUNT. FormRequest cuma menegakkan tipe
            // data; kecocokan dengan value_mode ditegakkan CHECK
            // constraint DB (migration Langkah 5.1) sebagai penjaga
            // sebenarnya.
            'unit_code' => [
                'nullable',
                'string',
                'max:20',
            ],
            'periodicity' => [
                'required',
                'string',
                Rule::in(['MONTHLY', 'DAILY', 'PER_UNIT', 'ONE_TIME', 'OTHER']),
            ],
            'description' => [
                'nullable',
                'string',
            ],
        ];
    }
}
