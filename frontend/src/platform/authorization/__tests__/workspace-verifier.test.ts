import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createWorkspaceCapabilityVerifier,
    type CapabilityProjectionOperations,
    type TenantCapabilitySuccess,
    type WorkspaceCapabilitySuccess,
} from '@/platform/authorization';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import type {
    WorkspaceSummary,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const otherOrganizationId =
    '018f3b6a-7c20-7bcd-9def-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7def-9def-1234567890ab';

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

const tenantWorkspace:
    WorkspaceSummary = {
        type:
            'TENANT',

        organizational_assignment_id:
            null,

        organization_id:
            null,

        organization_unit_id:
            null,

        label:
            'EduCore School',
    };

const organizationWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION',

        organizational_assignment_id:
            organizationalAssignmentId,

        organization_id:
            organizationId,

        organization_unit_id:
            null,

        label:
            'EduCore High School',
    };

const organizationUnitWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION_UNIT',

        organizational_assignment_id:
            organizationalAssignmentId,

        organization_id:
            organizationId,

        organization_unit_id:
            organizationUnitId,

        label:
            'Academic Office',
    };

const tenantCapabilitySuccess:
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

            /*
             * Empty permissions are still a valid canonical
             * context projection. Workspace verification is
             * not a permission decision.
             */
            permissions: [],
        },
    };

const organizationCapabilitySuccess:
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
            ],
        },
    };

const organizationUnitCapabilitySuccess:
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
                'dormitory.rooms.manage',
            ],
        },
    };

type TenantProjectionResult =
    Awaited<
        ReturnType<
            CapabilityProjectionOperations[
                'projectTenant'
            ]
        >
    >;

type WorkspaceProjectionResult =
    Awaited<
        ReturnType<
            CapabilityProjectionOperations[
                'projectWorkspace'
            ]
        >
    >;

interface OperationProbe {
    readonly tenantMembershipIds:
        string[];

    readonly workspaceRequests:
        Array<{
            readonly membershipId:
                string;

            readonly organizationalAssignmentId:
                string;
        }>;
}

function createProbe():
    OperationProbe {
    return {
        tenantMembershipIds:
            [],

        workspaceRequests:
            [],
    };
}

function createOperations(
    probe:
        OperationProbe,
    tenantResult:
        TenantProjectionResult = {
            ok:
                true,

            status:
                200,

            data:
                tenantCapabilitySuccess,
        },
    workspaceResult:
        WorkspaceProjectionResult = {
            ok:
                true,

            status:
                200,

            data:
                organizationCapabilitySuccess,
        },
): CapabilityProjectionOperations {
    return {
        async projectTenant(
            requestedMembershipId,
        ) {
            probe
                .tenantMembershipIds
                .push(
                    requestedMembershipId,
                );

            return tenantResult;
        },

        async projectWorkspace(
            requestedMembershipId,
            requestedOrganizationalAssignmentId,
        ) {
            probe
                .workspaceRequests
                .push({
                    membershipId:
                        requestedMembershipId,

                    organizationalAssignmentId:
                        requestedOrganizationalAssignmentId,
                });

            return workspaceResult;
        },
    };
}

describe(
    'Workspace capability verifier',
    () => {
        it('verifies TENANT through the tenant capability projection without requiring permissions', async () => {
            const probe =
                createProbe();

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        probe,
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    tenantWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    true,
            });

            expect(
                probe.tenantMembershipIds,
            ).toEqual([
                membershipId,
            ]);

            expect(
                probe.workspaceRequests,
            ).toEqual([]);
        });

        it('verifies ORGANIZATION through the exact Membership and organizational assignment locators', async () => {
            const probe =
                createProbe();

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        probe,
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    true,
            });

            expect(
                probe.tenantMembershipIds,
            ).toEqual([]);

            expect(
                probe.workspaceRequests,
            ).toEqual([
                {
                    membershipId,

                    organizationalAssignmentId,
                },
            ]);
        });

        it('verifies ORGANIZATION_UNIT against the exact canonical unit scope', async () => {
            const probe =
                createProbe();

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        probe,
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                organizationUnitCapabilitySuccess,
                        },
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationUnitWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    true,
            });
        });

        it('fails closed as a protocol failure when TENANT capability scope belongs to another Membership', async () => {
            const mismatched:
                TenantCapabilitySuccess = {
                    ...tenantCapabilitySuccess,

                    data: {
                        ...tenantCapabilitySuccess.data,

                        scope: {
                            ...tenantCapabilitySuccess
                                .data
                                .scope,

                            membership_id:
                                otherMembershipId,
                        },
                    },
                };

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                mismatched,
                        },
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    tenantWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    false,

                kind:
                    'protocol',

                status:
                    200,

                message:
                    'EduCore API returned an unexpected error response.',
            });
        });

        it('fails closed as a protocol failure when Workspace capability scope does not match the selected Organization', async () => {
            const mismatched:
                WorkspaceCapabilitySuccess = {
                    ...organizationCapabilitySuccess,

                    data: {
                        ...organizationCapabilitySuccess.data,

                        scope: {
                            ...organizationCapabilitySuccess
                                .data
                                .scope,

                            organization_id:
                                otherOrganizationId,
                        },
                    },
                };

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                mismatched,
                        },
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    false,

                kind:
                    'protocol',

                status:
                    200,

                message:
                    'EduCore API returned an unexpected error response.',
            });
        });

        it('fails closed when a successful capability transport omits its contract-required payload', async () => {
            const malformed:
                WorkspaceProjectionResult = {
                    ok:
                        true,

                    status:
                        200,

                    data:
                        undefined,
                };

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        malformed,
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationWorkspace,
                ),
            ).resolves.toEqual({
                ok:
                    false,

                kind:
                    'protocol',

                status:
                    200,

                message:
                    'EduCore API returned an unexpected error response.',
            });
        });

        it('preserves ORGANIZATIONAL_CONTEXT_DENIED unchanged for stale Workspace recovery', async () => {
            const denied:
                WorkspaceProjectionResult = {
                    ok:
                        false,

                    kind:
                        'response',

                    status:
                        403,

                    error: {
                        status:
                            'error',

                        code:
                            'ORGANIZATIONAL_CONTEXT_DENIED',

                        message:
                            'Organizational context is denied.',
                    },
                };

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        denied,
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationWorkspace,
                ),
            ).resolves.toBe(
                denied,
            );
        });

        it('preserves cancellation unchanged instead of inventing capability truth', async () => {
            const aborted:
                WorkspaceProjectionResult = {
                    ok:
                        false,

                    kind:
                        'aborted',

                    cause:
                        new Error(
                            'aborted',
                        ),
                };

            const verifier =
                createWorkspaceCapabilityVerifier(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        aborted,
                    ),
                );

            await expect(
                verifier.verify(
                    context,
                    organizationWorkspace,
                ),
            ).resolves.toBe(
                aborted,
            );
        });
    },
);
