<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EndEmploymentRequest extends FormRequest
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
            'end_date' => [
                'required',
                'date_format:Y-m-d',
            ],
        ];
    }
}
