<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEmployeeAccountRequest extends FormRequest
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
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
        ];
    }
}
