<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Core\Domain\Identity\User;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null, bool $emailRequired = true): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId, $emailRequired),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        // Panjang mengikuti kolom persons.name (PRD-000 §5).
        return ['required', 'string', 'max:200'];
    }

    /**
     * Get the validation rules used to validate user emails.
     * Akun wajib punya email atau username (CHECK users_login_identifier_check).
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null, bool $required = true): array
    {
        return [
            // Email opsional bila akun punya username untuk login (PRD-000 §4.7).
            $required ? 'required' : 'nullable',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
