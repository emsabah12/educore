<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginTokenRequest extends FormRequest
{
    /**
     * Global login endpoint is public.
     *
     * Authorization here only controls whether the request may enter
     * validation. Credential verification happens inside the canonical
     * authentication application boundary.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the global login identifier before validation.
     *
     * Both canonical email and username identifiers are case-insensitive:
     *
     * - email is stored/compared in lowercase canonical form;
     * - username is stored/compared in lowercase canonical form.
     *
     * Password input is intentionally left untouched.
     */
    protected function prepareForValidation(): void
    {
        $identifier = $this->input('identifier');

        if (! is_string($identifier)) {
            return;
        }

        $this->merge([
            'identifier' => strtolower(
                trim($identifier),
            ),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:6',
            ],
        ];
    }
}
