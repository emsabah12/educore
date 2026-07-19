<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignRoleRequest extends FormRequest
{
    /**
     * Menentukan apakah pengguna diizinkan untuk membuat request ini.
     */
    public function authorize(): bool
    {
        // Otorisasi utama ditangani di level middleware rute
        return true;
    }

    /**
     * Menetapkan aturan validasi ketat untuk input payload.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'uuid',
                'exists:roles,id' // Mengunci kepastian bahwa UUID role terdaftar di sistem master
            ],
        ];
    }

    /**
     * Kustomisasi pesan eror human-readable untuk klien API.
     */
    public function messages(): array
    {
        return [
            'role_id.required' => 'Atribut role_id wajib disertakan dalam payload request.',
            'role_id.uuid'     => 'Format data role_id wajib berupa UUID v4/v7 yang valid.',
            'role_id.exists'   => 'Identitas role_id tidak ditemukan dalam sistem master data.',
        ];
    }
}
