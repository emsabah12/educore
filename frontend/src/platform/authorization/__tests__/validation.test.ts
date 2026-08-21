import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import {
    validateTenantCapabilityProjection,
    validateWorkspaceCapabilityProjection,
    type OrganizationCapabilityScopeExpectation,
    type OrganizationUnitCapabilityScopeExpectation,
    type TenantCapabilitySuccess,
    type WorkspaceCapabilitySuccess,
} from '@/platform/authorization';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const otherTenantId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const otherOrganizationalAssignmentId =
    '018f3b6a-7c20-7abc-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7bcd-9abc-1234567890ab';

const otherOrganizationId =
    '018f3b6a-7c20-7cde-9abc-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7def-9def-1234567890ab';

const otherOrganizationUnitId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const context:
    CanonicalMembershipContext = {
        membership: {
            id:
                membershipId,
            status:
                'ACTIVE',
        },

        tenant: {
            id:
                tenantId,
            name:
                'EduCore School',
            subdomain:
                'educore-school',
        },
    };

const tenantCapability:
    TenantCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'tenant',
                tenant_id:
                    tenantId,
                membership_id:
                    membershipId,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
            ],
        },
    };

const organizationExpectation:
    OrganizationCapabilityScopeExpectation = {
        type:
            'organization',
        organizationalAssignmentId,
        organizationId,
        organizationUnitId:
            null,
    };

const organizationCapability:
    WorkspaceCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'organization',
                tenant_id:
                    tenantId,
                membership_id:
                    membershipId,
                organizational_assignment_id:
                    organizationalAssignmentId,
                organization_id:
                    organizationId,
                organization_unit_id:
                    null,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
                'dormitory.rooms.manage',
            ],
        },
    };

const organizationUnitExpectation:
    OrganizationUnitCapabilityScopeExpectation = {
        type:
            'organization_unit',
        organizationalAssignmentId,
        organizationId,
        organizationUnitId,
    };

const organizationUnitCapability:
    WorkspaceCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'organization_unit',
                tenant_id:
                    tenantId,
                membership_id:
                    membershipId,
                organizational_assignment_id:
                    organizationalAssignmentId,
                organization_id:
                    organizationId,
                organization_unit_id:
                    organizationUnitId,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
            ],
        },
    };

describe(
    'Capability semantic validation',
    () => {
        it('accepts a canonical TENANT projection bound to the current Membership and Tenant', () => {
            const result =
                validateTenantCapabilityProjection(
                    context,
                    tenantCapability,
                );

            expect(result).toEqual({
                ok:
                    true,
                data:
                    tenantCapability.data,
            });
        });

        it('rejects a TENANT projection for another Membership', () => {
            const result =
                validateTenantCapabilityProjection(
                    context,
                    {
                        ...tenantCapability,

                        data: {
                            ...tenantCapability.data,

                            scope: {
                                ...tenantCapability
                                    .data
                                    .scope,

                                membership_id:
                                    otherMembershipId,
                            },
                        },
                    },
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a TENANT projection for another Tenant', () => {
            const result =
                validateTenantCapabilityProjection(
                    context,
                    {
                        ...tenantCapability,

                        data: {
                            ...tenantCapability.data,

                            scope: {
                                ...tenantCapability
                                    .data
                                    .scope,

                                tenant_id:
                                    otherTenantId,
                            },
                        },
                    },
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a structurally invalid TENANT permission projection', () => {
            const result =
                validateTenantCapabilityProjection(
                    context,
                    {
                        ...tenantCapability,

                        data: {
                            ...tenantCapability.data,

                            permissions: [
                                'academic.grades.write',
                                'academic.grades.write',
                            ],
                        },
                    },
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'invalid-payload',
            });
        });

        it('accepts a canonical ORGANIZATION projection', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    organizationExpectation,
                    organizationCapability,
                );

            expect(result).toEqual({
                ok:
                    true,
                data:
                    organizationCapability.data,
            });
        });

        it('accepts a canonical ORGANIZATION_UNIT projection', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    organizationUnitExpectation,
                    organizationUnitCapability,
                );

            expect(result).toEqual({
                ok:
                    true,
                data:
                    organizationUnitCapability.data,
            });
        });

        it('rejects a Workspace projection with another scope type', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    organizationExpectation,
                    organizationUnitCapability,
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a Workspace projection for another organizational assignment', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    {
                        ...organizationExpectation,
                        organizationalAssignmentId:
                            otherOrganizationalAssignmentId,
                    },
                    organizationCapability,
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a Workspace projection for another Organization', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    {
                        ...organizationExpectation,
                        organizationId:
                            otherOrganizationId,
                    },
                    organizationCapability,
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a Workspace projection for another Organization Unit', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    {
                        ...organizationUnitExpectation,
                        organizationUnitId:
                            otherOrganizationUnitId,
                    },
                    organizationUnitCapability,
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'scope-mismatch',
            });
        });

        it('rejects a structurally invalid Workspace capability projection', () => {
            const result =
                validateWorkspaceCapabilityProjection(
                    context,
                    organizationExpectation,
                    {
                        ...organizationCapability,

                        data: {
                            ...organizationCapability.data,

                            permissions: [
                                '',
                            ],
                        },
                    },
                );

            expect(result).toEqual({
                ok:
                    false,
                kind:
                    'invalid-payload',
            });
        });
    },
);
