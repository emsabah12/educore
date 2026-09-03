<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $employmentTypeId = $this->input('employment_type_id');
        $employmentClassificationId = $this->input('employment_classification_id');

        $this->merge([
            'employment_type_id' => is_string($employmentTypeId)
                && trim($employmentTypeId) !== ''
                ? trim($employmentTypeId)
                : null,
            'employment_classification_id' => is_string($employmentClassificationId)
                && trim($employmentClassificationId) !== ''
                ? trim($employmentClassificationId)
                : null,
        ]);
    }

    /**
     * Catatan: rule di sini HANYA memvalidasi "apakah UUID ini eksis dan
     * milik tenant yang benar" — ini kegagalan bentuk input (422).
     *
     * Rule ini SENGAJA tidak memeriksa `is_active`. Pengecekan
     * "katalog harus aktif" adalah aturan bisnis (bukan kesalahan bentuk
     * input), jadi tetap ditegakkan di EmploymentLifecycleService dan
     * dilaporkan sebagai domain conflict (409) — bukan 422 — supaya
     * pemisahan tanggung jawab antara "input tidak valid" vs "aturan
     * bisnis dilanggar" tetap jelas.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'employment_type_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_types', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'employment_classification_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_classifications', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
            ],
        ];
    }
}
