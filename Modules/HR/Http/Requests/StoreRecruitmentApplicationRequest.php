<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRecruitmentApplicationRequest extends FormRequest
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
            'candidate_id' => [
                'required',
                'uuid',
                Rule::exists('recruitment_candidates', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query
                            ->where('tenant_id', $tenantId)
                            ->where('status', 'ACTIVE'),
                    ),
            ],
        ];
    }
}
