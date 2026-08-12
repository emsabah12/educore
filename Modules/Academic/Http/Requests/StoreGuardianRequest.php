<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['nama', 'no_hp'] as $field) {
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
            'nama' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'no_hp' => [
                'nullable',
                'string',
                'max:25',
                'regex:/^\\+?[0-9\\s-]+$/',
            ],
            'email' => [
                'prohibited',
            ],
            'alamat_domisili' => [
                'prohibited',
            ],
        ];
    }
}
