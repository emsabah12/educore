<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bentuk payload identik untuk `createDraft()` DAN `correct()` di
 * `CompensationAssignmentService` — keduanya menerima data lengkap
 * baris baru (lihat `buildDraft()`, satu-satunya tempat yang
 * memvalidasi bisnisnya). FormRequest ini murni memvalidasi TIPE
 * data mentah; validasi bisnis (value_mode vs amount/rate, Employment
 * ACTIVE, dst.) tetap tanggung jawab service.
 */
final class StoreCompensationAssignmentRequest extends FormRequest
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
            'compensation_component_id' => [
                'required',
                'uuid',
            ],
            'employment_position_assignment_id' => [
                'nullable',
                'uuid',
            ],
            'amount' => [
                'nullable',
                'numeric',
                'gt:0',
            ],
            'rate' => [
                'nullable',
                'numeric',
                'gt:0',
            ],
            'currency_code' => [
                'required',
                'string',
                'size:3',
            ],
            'effective_from' => [
                'required',
                'date',
            ],
            'effective_to' => [
                'nullable',
                'date',
            ],
            'reason' => [
                'nullable',
                'string',
            ],
        ];
    }
}
