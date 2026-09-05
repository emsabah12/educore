<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRecruitmentCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `identifiers` OPSIONAL — kandidat bisa dibuat dulu tanpa
     * identifier kuat (mis. baru sekadar submit lamaran online), lalu
     * identifier ditambahkan belakangan setelah verifikasi dokumen.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => [
                'required',
                'string',
                'max:255',
            ],
            'birth_date' => [
                'nullable',
                'date',
                'before:today',
            ],
            'primary_email' => [
                'nullable',
                'string',
                'email',
                'max:320',
            ],
            'primary_phone' => [
                'nullable',
                'string',
                'max:32',
            ],
            'source' => [
                'nullable',
                'string',
                'max:50',
            ],
            'identifiers' => [
                'nullable',
                'array',
            ],
            'identifiers.*.type' => [
                'required_with:identifiers',
                'string',
                'max:50',
            ],
            'identifiers.*.issuing_country_code' => [
                'required_with:identifiers',
                'string',
                'size:2',
            ],
            'identifiers.*.value' => [
                'required_with:identifiers',
                'string',
            ],
        ];
    }
}
