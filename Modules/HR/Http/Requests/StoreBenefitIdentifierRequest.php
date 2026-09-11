<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `value` adalah raw identifier sensitif (mis. nomor BPJS) — WAJIB
 * dikirim via HTTPS, TIDAK PERNAH di-log oleh controller/repository
 * (lihat `EloquentEmployeeBenefitIdentifierRepository`), dan tidak
 * pernah dikembalikan balik dalam response `store()`.
 */
final class StoreBenefitIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'identifier_type' => [
                'required',
                'string',
                'max:50',
            ],
            'value' => [
                'required',
                'string',
                'max:255',
            ],
            'issuer' => [
                'nullable',
                'string',
                'max:150',
            ],
            'issued_at' => [
                'nullable',
                'date',
            ],
            'expires_at' => [
                'nullable',
                'date',
            ],
        ];
    }
}
