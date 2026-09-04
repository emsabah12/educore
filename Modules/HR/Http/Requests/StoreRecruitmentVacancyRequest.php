<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRecruitmentVacancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $organizationUnitId = $this->input('organization_unit_id');

        $this->merge([
            'organization_unit_id' => is_string($organizationUnitId)
                && trim($organizationUnitId) !== ''
                ? trim($organizationUnitId)
                : null,
        ]);
    }

    /**
     * Catatan: sama seperti FormRequest lain di modul ini, rule di sini
     * hanya memvalidasi bentuk input (eksistensi & kepemilikan tenant).
     * Aturan bisnis "harus AKTIF" dan "unit harus milik organisasi yang
     * dipilih" tetap ditegakkan RecruitmentVacancyLifecycleService dan
     * dilaporkan sebagai domain conflict (409), bukan 422.
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
            'code' => [
                'required',
                'string',
                'max:50',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
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
            'organization_id' => [
                'required',
                'uuid',
                Rule::exists('organizations', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'organization_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
            'requested_headcount' => [
                'required',
                'integer',
                'min:1',
            ],
            'description' => [
                'nullable',
                'string',
            ],
        ];
    }
}
