<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveEntitlementPolicy;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;

/**
 * HR-004 §15.1 — "Historical semantic fields cannot be mutated
 * incompatibly after use." `updateType()` menegakkan ini: begitu
 * sebuah LeaveType direferensikan oleh Entitlement Policy, Entitlement,
 * atau Leave Request manapun, `category`/`balance_mode`/`unit` menjadi
 * beku — mengubahnya akan menafsirkan ulang riwayat yang sudah ada.
 */
final readonly class LeaveTypeService
{
    private const array IMMUTABLE_ONCE_REFERENCED_FIELDS = [
        'category',
        'balance_mode',
        'unit',
    ];

    /**
     * @param array{
     *     code: string,
     *     name: string,
     *     category: string,
     *     balance_mode: string,
     *     unit: string,
     *     description?: string|null,
     *     is_active?: bool,
     * } $data
     */
    public function createType(string $tenantId, array $data): LeaveType
    {
        return LeaveType::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LeaveLifecycleException LEAVE_TYPE_FIELD_IMMUTABLE.
     */
    public function updateType(string $tenantId, string $leaveTypeId, array $data): LeaveType
    {
        $leaveType = $this->findForTenant($leaveTypeId, $tenantId);

        if ($this->isReferenced($tenantId, $leaveTypeId)) {
            foreach (self::IMMUTABLE_ONCE_REFERENCED_FIELDS as $field) {
                if (
                    array_key_exists($field, $data)
                    && $data[$field] !== $leaveType->{$field}
                ) {
                    throw new LeaveLifecycleException(
                        sprintf(
                            'LEAVE_TYPE_FIELD_IMMUTABLE: field [%s] cannot be changed once LeaveType [%s] has been referenced by Entitlement Policy, Entitlement, or Leave Request records.',
                            $field,
                            $leaveTypeId,
                        ),
                    );
                }
            }
        }

        $leaveType->fill($data);
        $leaveType->save();

        return $leaveType->refresh();
    }

    public function deactivate(string $tenantId, string $leaveTypeId): LeaveType
    {
        $leaveType = $this->findForTenant($leaveTypeId, $tenantId);
        $leaveType->is_active = false;
        $leaveType->save();

        return $leaveType->refresh();
    }

    private function isReferenced(string $tenantId, string $leaveTypeId): bool
    {
        return LeaveEntitlementPolicy::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('leave_type_id', $leaveTypeId)
            ->exists()
            || LeaveEntitlement::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('leave_type_id', $leaveTypeId)
                ->exists()
            || LeaveRequest::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('leave_type_id', $leaveTypeId)
                ->exists();
    }

    private function findForTenant(string $leaveTypeId, string $tenantId): LeaveType
    {
        $leaveType = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $leaveTypeId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($leaveType === null) {
            throw (new ModelNotFoundException)->setModel(
                LeaveType::class,
                [$leaveTypeId],
            );
        }

        return $leaveType;
    }
}
