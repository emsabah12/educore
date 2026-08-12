<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteGuardianStudentAssociationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['guardian_id', 'student_id'] as $field) {
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
            'guardian_id' => ['required', 'string', 'uuid:7'],
            'student_id' => ['required', 'string', 'uuid:7'],
            'hubungan' => ['prohibited'],
            'relation' => ['prohibited'],
            'walisantri_id' => ['prohibited'],
            'santri_id' => ['prohibited'],
        ];
    }
}
