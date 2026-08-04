<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginTokenRequest extends FormRequest
{
    /**
     * Endpoint login bersifat publik.
     *
     * Authorization pada method ini hanya menentukan apakah request
     * boleh melewati Form Request, bukan memverifikasi credential.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi input dilakukan sebelum rule validation dijalankan.
     *
     * Email dibuat lowercase dan dibersihkan dari whitespace agar
     * repository menerima bentuk canonical yang konsisten.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge([
                'email' => strtolower(trim($email)),
            ]);
        }

        $tenantUuid = $this->input('tenant_uuid');

        if (is_string($tenantUuid)) {
            $this->merge([
                'tenant_uuid' => trim($tenantUuid),
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:6',
            ],
            'tenant_uuid' => [
                'required',
                'string',
                'uuid',
            ],
        ];
    }
}
