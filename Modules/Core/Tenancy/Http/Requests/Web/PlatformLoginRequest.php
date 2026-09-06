<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

final class PlatformLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $identifier = $this->input('identifier');

        if (is_string($identifier)) {
            $this->merge([
                'identifier' => strtolower(trim($identifier)),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'identifier' => [
                'required',
                'string',
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }
}
