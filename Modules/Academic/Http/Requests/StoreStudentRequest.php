<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['class_id', 'nama', 'nis', 'nisn'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'class_id' => [
                'required',
                'string',
                'uuid:7',
            ],
            'nama' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'nis' => [
                'nullable',
                'string',
                'max:50',
            ],
            'nisn' => [
                'nullable',
                'string',
                'max:20',
            ],
            'email' => [
                'prohibited',
            ],
        ];
    }
}
