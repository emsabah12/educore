<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Email dibuat huruf kecil SEBELUM validasi, supaya cek "email sudah dipakai"
     * sama persis dengan nilai yang nanti disimpan (users.email selalu huruf kecil).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $email = Str::lower(trim($this->input('email')));

            $this->merge([
                'email' => $email === '' ? null : $email,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        // Email boleh dikosongkan bila akun punya username untuk login (PRD-000 §4.7).
        return $this->profileRules($user?->id, emailRequired: $user?->username === null);
    }
}
