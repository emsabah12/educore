<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrganizationalAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Existence/scope of `membership_id` and `organization_unit_id`
     * is validated HERE (422 with a clear per-field message) rather
     * than left to OrganizationalAssignmentService's own internal
     * checks (404 RuntimeException) — the service's checks remain
     * as defense-in-depth for a race between validation and
     * execution, never the primary path.
     *
     * `organization_unit_id` distinguishes the two assignment modes:
     * present → assignToUnit(), omitted/null → assignToOrganization()
     * (ADR-018 §2.3). Send explicit `null`, not an empty string, to
     * mean "organization-level" — mirrors StoreOrganizationRequest's
     * `code` convention.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        $organizationId = $this->route('organization');
        $organizationId = is_string($organizationId) ? $organizationId : '';

        return [
            'membership_id' => [
                'required',
                'uuid',
                Rule::exists('memberships', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query
                            ->where('tenant_id', $tenantId)
                            ->where('status', 'ACTIVE'),
                    ),
            ],
            'organization_unit_id' => [
                'nullable',
                'uuid',
                Rule::exists('organization_units', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query
                            ->where('tenant_id', $tenantId)
                            ->where('organization_id', $organizationId)
                            ->where('is_active', true)
                            ->whereNull('deleted_at'),
                    ),
            ],
        ];
    }
}
