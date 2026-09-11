<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBenefitParticipationRequest extends FormRequest
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
            'benefit_program_id' => [
                'required',
                'uuid',
            ],
            'beneficiary_person_id' => [
                'nullable',
                'uuid',
            ],
            'effective_from' => [
                'required',
                'date',
            ],
            'effective_to' => [
                'nullable',
                'date',
            ],
            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }
}
