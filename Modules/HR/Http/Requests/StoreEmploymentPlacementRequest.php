<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmploymentPlacementRequest extends FormRequest
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
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'organizational_assignment_id' => [
                'required',
                'uuid',
                Rule::exists('organizational_assignments', 'id')
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
