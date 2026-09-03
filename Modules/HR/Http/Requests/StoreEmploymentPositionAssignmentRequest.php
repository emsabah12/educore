<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmploymentPositionAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $placementId = $this->input('employment_placement_id');

        $this->merge([
            'employment_placement_id' => is_string($placementId)
                && trim($placementId) !== ''
                ? trim($placementId)
                : null,
        ]);
    }

    /**
     * Catatan: sama seperti StoreEmploymentRequest (Step 5), rule ini
     * hanya memvalidasi bentuk input ("apakah id eksis & milik tenant
     * yang benar"). Pengecekan bisnis "Placement harus terbuka DAN
     * benar-benar milik Employment ini" tetap di
     * EmploymentPositionAssignmentService dan dilaporkan sebagai domain
     * conflict (409), bukan 422.
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
            'position_id' => [
                'required',
                'uuid',
                Rule::exists('positions', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'employment_placement_id' => [
                'nullable',
                'uuid',
                Rule::exists('employment_placements', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'effective_from' => [
                'required',
                'date_format:Y-m-d',
            ],
            'is_primary' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
