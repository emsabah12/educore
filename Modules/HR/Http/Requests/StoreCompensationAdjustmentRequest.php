<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\CompensationAdjustment;

final class StoreCompensationAdjustmentRequest extends FormRequest
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
            'compensation_component_id' => [
                'nullable',
                'uuid',
            ],
            'adjustment_type' => [
                'required',
                'string',
                Rule::in([
                    CompensationAdjustment::TYPE_ONE_TIME_EARNING,
                    CompensationAdjustment::TYPE_COMPENSATION_CORRECTION,
                ]),
            ],
            'amount' => [
                'required',
                'numeric',
                'gt:0',
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
            ],
            'target_period_start' => [
                'required',
                'date',
            ],
            'target_period_end' => [
                'required',
                'date',
            ],
            'reason' => [
                'required',
                'string',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:120',
            ],
        ];
    }
}
