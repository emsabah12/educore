import type {
    CanonicalMembershipContext,
} from '@/platform/membership';

import type {
    PermissionName,
    TenantCapabilityData,
    TenantCapabilitySuccess,
    WorkspaceCapabilityData,
    WorkspaceCapabilityScope,
    WorkspaceCapabilitySuccess,
} from '@/platform/authorization/contract';

export interface OrganizationCapabilityScopeExpectation {
    readonly type:
        'organization';

    readonly organizationalAssignmentId:
        WorkspaceCapabilityScope[
            'organizational_assignment_id'
        ];

    readonly organizationId:
        WorkspaceCapabilityScope[
            'organization_id'
        ];

    readonly organizationUnitId:
        null;
}

export interface OrganizationUnitCapabilityScopeExpectation {
    readonly type:
        'organization_unit';

    readonly organizationalAssignmentId:
        WorkspaceCapabilityScope[
            'organizational_assignment_id'
        ];

    readonly organizationId:
        WorkspaceCapabilityScope[
            'organization_id'
        ];

    readonly organizationUnitId:
        Exclude<
            WorkspaceCapabilityScope[
                'organization_unit_id'
            ],
            null
        >;
}

export type WorkspaceCapabilityScopeExpectation =
    | OrganizationCapabilityScopeExpectation
    | OrganizationUnitCapabilityScopeExpectation;

export interface ValidCapabilityProjection<
    Data,
> {
    readonly ok:
        true;

    readonly data:
        Data;
}

export interface InvalidCapabilityProjection {
    readonly ok:
        false;

    readonly kind:
        | 'invalid-payload'
        | 'scope-mismatch';
}

export type CapabilityProjectionValidationResult<
    Data,
> =
    | ValidCapabilityProjection<Data>
    | InvalidCapabilityProjection;

function isRecord(
    value: unknown,
): value is Record<
    string,
    unknown
> {
    return (
        typeof value
            === 'object'
        && value !== null
        && ! Array.isArray(
            value,
        )
    );
}

function isNonEmptyString(
    value: unknown,
): value is string {
    return (
        typeof value
            === 'string'
        && value.length
            > 0
    );
}

function isPermissionNames(
    value: unknown,
): value is PermissionName[] {
    if (
        ! Array.isArray(
            value,
        )
    ) {
        return false;
    }

    const names =
        new Set<string>();

    for (
        const item
        of value
    ) {
        if (
            ! isNonEmptyString(
                item,
            )
        ) {
            return false;
        }

        if (
            names.has(
                item,
            )
        ) {
            return false;
        }

        names.add(
            item,
        );
    }

    return true;
}

function isTenantCapabilitySuccess(
    value: unknown,
): value is TenantCapabilitySuccess {
    if (
        ! isRecord(
            value,
        )
        || value.status
            !== 'success'
    ) {
        return false;
    }

    const data =
        value.data;

    if (
        ! isRecord(
            data,
        )
    ) {
        return false;
    }

    const scope =
        data.scope;

    if (
        ! isRecord(
            scope,
        )
    ) {
        return false;
    }

    return (
        scope.type
            === 'tenant'
        && isNonEmptyString(
            scope.tenant_id,
        )
        && isNonEmptyString(
            scope.membership_id,
        )
        && typeof data
            .is_global_superadmin
            === 'boolean'
        && isPermissionNames(
            data.permissions,
        )
    );
}

function isWorkspaceCapabilitySuccess(
    value: unknown,
): value is WorkspaceCapabilitySuccess {
    if (
        ! isRecord(
            value,
        )
        || value.status
            !== 'success'
    ) {
        return false;
    }

    const data =
        value.data;

    if (
        ! isRecord(
            data,
        )
    ) {
        return false;
    }

    const scope =
        data.scope;

    if (
        ! isRecord(
            scope,
        )
    ) {
        return false;
    }

    if (
        scope.type
            !== 'organization'
        && scope.type
            !== 'organization_unit'
    ) {
        return false;
    }

    return (
        isNonEmptyString(
            scope.tenant_id,
        )
        && isNonEmptyString(
            scope.membership_id,
        )
        && isNonEmptyString(
            scope
                .organizational_assignment_id,
        )
        && isNonEmptyString(
            scope.organization_id,
        )
        && (
            scope.organization_unit_id
                === null
            || isNonEmptyString(
                scope.organization_unit_id,
            )
        )
        && typeof data
            .is_global_superadmin
            === 'boolean'
        && isPermissionNames(
            data.permissions,
        )
    );
}

function invalidPayload():
    InvalidCapabilityProjection {
    return {
        ok:
            false,
        kind:
            'invalid-payload',
    };
}

function scopeMismatch():
    InvalidCapabilityProjection {
    return {
        ok:
            false,
        kind:
            'scope-mismatch',
    };
}

export function validateTenantCapabilityProjection(
    context:
        CanonicalMembershipContext,
    value:
        unknown,
): CapabilityProjectionValidationResult<
    TenantCapabilityData
> {
    if (
        ! isTenantCapabilitySuccess(
            value,
        )
    ) {
        return invalidPayload();
    }

    if (
        value.data.scope
            .membership_id
            !== context.membership.id
        || value.data.scope
            .tenant_id
            !== context.tenant.id
    ) {
        return scopeMismatch();
    }

    return {
        ok:
            true,
        data:
            value.data,
    };
}

export function validateWorkspaceCapabilityProjection(
    context:
        CanonicalMembershipContext,
    expected:
        WorkspaceCapabilityScopeExpectation,
    value:
        unknown,
): CapabilityProjectionValidationResult<
    WorkspaceCapabilityData
> {
    if (
        ! isWorkspaceCapabilitySuccess(
            value,
        )
    ) {
        return invalidPayload();
    }

    const scope =
        value.data.scope;

    if (
        scope.membership_id
            !== context.membership.id
        || scope.tenant_id
            !== context.tenant.id
        || scope.type
            !== expected.type
        || scope
            .organizational_assignment_id
            !== expected
                .organizationalAssignmentId
        || scope.organization_id
            !== expected.organizationId
        || scope.organization_unit_id
            !== expected
                .organizationUnitId
    ) {
        return scopeMismatch();
    }

    return {
        ok:
            true,
        data:
            value.data,
    };
}
