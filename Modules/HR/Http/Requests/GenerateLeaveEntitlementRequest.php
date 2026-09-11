<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerateLeaveEntitlementRequest extends FormRequest
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
        $tenantId = $this->attributes->get('authenticated_tenant_id');
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'leave_type_id' => [
                'required',
                'uuid',
                Rule::exists('leave_types', 'id')
                    ->where(static fn (Builder $query): Builder => $query->where('tenant_id', $tenantId)),
            ],
            'period_start' => [
                'required',
                'date',
            ],
            'period_end' => [
                'required',
                'date',
                'after_or_equal:period_start',
            ],
        ];
    }
}
