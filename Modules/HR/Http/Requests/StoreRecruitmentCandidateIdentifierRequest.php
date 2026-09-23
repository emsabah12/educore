<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §Menambahkan identifier kuat (mis. NIK) ke Candidate yang SUDAH
 * ADA — melengkapi jalur "buat dulu tanpa identifier, lengkapi
 * belakangan setelah verifikasi dokumen" yang sudah diantisipasi
 * sejak `StoreRecruitmentCandidateRequest` (lihat docblock di sana),
 * tapi belum pernah punya endpoint sampai sekarang.
 *
 * `value` adalah raw identifier sensitif (mis. NIK) — WAJIB dikirim
 * via HTTPS, TIDAK PERNAH di-log oleh controller/repository, dan
 * tidak pernah dikembalikan balik dalam response `store()` (sama
 * persis kontrak `StoreBenefitIdentifierRequest`).
 */
final class StoreRecruitmentCandidateIdentifierRequest extends FormRequest
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
            'type' => [
                'required',
                'string',
                'max:50',
            ],
            'issuing_country_code' => [
                'required',
                'string',
                'size:2',
            ],
            'value' => [
                'required',
                'string',
            ],
        ];
    }
}
