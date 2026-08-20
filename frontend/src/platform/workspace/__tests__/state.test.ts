import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import {
    createInitialWorkspaceContextState,
    workspaceContextReducer,
    type WorkspaceDiscoveryData,
    type WorkspaceSummary,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const otherTenantId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const organizationAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const otherOrganizationAssignmentId =
    '018f3b6a-7c20-7abc-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7bcd-9abc-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7cde-9abc-1234567890ab';

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
            organizationAssignmentId,
        organization_id:
            organizationId,
        organization_unit_id:
            null,
        label:
            'SMA EduCore',
    };

const unitWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION_UNIT',
        organizational_assignment_id:
            otherOrganizationAssignmentId,
        organization_id:
            organizationId,
        organization_unit_id:
            organizationUnitId,
        label:
            'Unit Kurikulum',
    };

const discovery:
    WorkspaceDiscoveryData = {
        tenant: {
            id:
                tenantId,
            name:
                'EduCore School',
        },

        workspaces: [
            tenantWorkspace,
            organizationWorkspace,
            unitWorkspace,
        ],
    };

const networkFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'network',
        cause:
            new TypeError(
                'Network unavailable',
            ),
    };

const contextDeniedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status: 403,
        error: {
            status:
                'error',
            code:
                'ORGANIZATIONAL_CONTEXT_DENIED',
            message:
                'Organizational context denied.',
        },
    };

function createReadyState() {
    const discovering =
        workspaceContextReducer(
            createInitialWorkspaceContextState(),
            {
                type:
                    'DISCOVERY_STARTED',
                context,
            },
        );

    return workspaceContextReducer(
        discovering,
        {
            type:
                'DISCOVERY_READY',
            discovery,
        },
    );
}

describe(
    'WorkspaceContext state',
    () => {
        it('starts unresolved', () => {
            expect(
                createInitialWorkspaceContextState(),
            ).toEqual({
                status:
                    'unresolved',
            });
        });

        it('selects the canonical TENANT Workspace as the safe discovery baseline', () => {
            const state =
                createReadyState();

            expect(state).toEqual({
                status:
                    'ready',
                context,
                tenant:
                    discovery.tenant,
                workspaces:
                    discovery.workspaces,
                current:
                    tenantWorkspace,
                failure:
                    null,
            });
        });

        it('fails closed when Workspace discovery belongs to another Tenant', () => {
            const discovering =
                workspaceContextReducer(
                    createInitialWorkspaceContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context,
                    },
                );

            expect(
                () =>
                    workspaceContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_READY',
                            discovery: {
                                ...discovery,
                                tenant: {
                                    id:
                                        otherTenantId,
                                    name:
                                        'Other Tenant',
                                },
                            },
                        },
                    ),
            ).toThrow(
                'EduCore WorkspaceContext discovery Tenant does not match the canonical Membership/Tenant context.',
            );
        });

        it('fails closed when discovery has no TENANT Workspace', () => {
            const discovering =
                workspaceContextReducer(
                    createInitialWorkspaceContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context,
                    },
                );

            expect(
                () =>
                    workspaceContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_READY',
                            discovery: {
                                tenant:
                                    discovery.tenant,
                                workspaces: [
                                    organizationWorkspace,
                                    unitWorkspace,
                                ],
                            },
                        },
                    ),
            ).toThrow(
                'EduCore WorkspaceContext discovery must contain exactly one TENANT Workspace.',
            );
        });

        it('fails closed when discovery contains multiple TENANT Workspaces', () => {
            const discovering =
                workspaceContextReducer(
                    createInitialWorkspaceContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context,
                    },
                );

            expect(
                () =>
                    workspaceContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_READY',
                            discovery: {
                                tenant:
                                    discovery.tenant,
                                workspaces: [
                                    tenantWorkspace,
                                    {
                                        ...tenantWorkspace,
                                        label:
                                            'Duplicate Tenant',
                                    },
                                ],
                            },
                        },
                    ),
            ).toThrow(
                'EduCore WorkspaceContext discovery must contain exactly one TENANT Workspace.',
            );
        });

        it('fails closed when organizational assignment locators are duplicated', () => {
            const duplicateUnit:
                WorkspaceSummary = {
                    type:
                        'ORGANIZATION_UNIT',
                    organizational_assignment_id:
                        organizationAssignmentId,
                    organization_id:
                        organizationId,
                    organization_unit_id:
                        organizationUnitId,
                    label:
                        'Duplicate Assignment',
                };

            const discovering =
                workspaceContextReducer(
                    createInitialWorkspaceContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context,
                    },
                );

            expect(
                () =>
                    workspaceContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_READY',
                            discovery: {
                                tenant:
                                    discovery.tenant,
                                workspaces: [
                                    tenantWorkspace,
                                    organizationWorkspace,
                                    duplicateUnit,
                                ],
                            },
                        },
                    ),
            ).toThrow(
                'EduCore WorkspaceContext discovery contains duplicate organizational assignment locators.',
            );
        });

        it('keeps the current Workspace authoritative while a canonical catalog target is switching', () => {
            const ready =
                createReadyState();

            const switching =
                workspaceContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        target: {
                            ...organizationWorkspace,
                            label:
                                'Untrusted Caller Label',
                        },
                    },
                );

            expect(switching).toEqual({
                status:
                    'switching',
                context,
                tenant:
                    discovery.tenant,
                workspaces:
                    discovery.workspaces,
                current:
                    tenantWorkspace,
                target:
                    organizationWorkspace,
            });
        });

        it('treats selecting the current Workspace as a no-op', () => {
            const ready =
                createReadyState();

            const state =
                workspaceContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        target:
                            tenantWorkspace,
                    },
                );

            expect(state).toBe(
                ready,
            );
        });

        it('rejects a switch target that is not in the current Workspace catalog', () => {
            const ready =
                createReadyState();

            const unavailableTarget:
                WorkspaceSummary = {
                    type:
                        'ORGANIZATION',
                    organizational_assignment_id:
                        '018f3b6a-7c20-7def-9def-1234567890ab',
                    organization_id:
                        '018f3b6a-7c20-7abc-8abc-1234567890ab',
                    organization_unit_id:
                        null,
                    label:
                        'Unavailable Workspace',
                };

            expect(
                () =>
                    workspaceContextReducer(
                        ready,
                        {
                            type:
                                'SWITCH_STARTED',
                            target:
                                unavailableTarget,
                        },
                    ),
            ).toThrow(
                'EduCore WorkspaceContext switch target is not available in the current Workspace catalog.',
            );
        });

        it('commits the target Workspace only after target verification', () => {
            const ready =
                createReadyState();

            const switching =
                workspaceContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        target:
                            organizationWorkspace,
                    },
                );

            expect(
                switching.status,
            ).toBe(
                'switching',
            );

            if (
                switching.status
                    !== 'switching'
            ) {
                throw new Error(
                    'Expected Workspace switching state.',
                );
            }

            expect(
                switching.current,
            ).toEqual(
                tenantWorkspace,
            );

            const verified =
                workspaceContextReducer(
                    switching,
                    {
                        type:
                            'TARGET_VERIFIED',
                    },
                );

            expect(verified).toEqual({
                status:
                    'ready',
                context,
                tenant:
                    discovery.tenant,
                workspaces:
                    discovery.workspaces,
                current:
                    organizationWorkspace,
                failure:
                    null,
            });
        });

        it('restores the previous current Workspace when target verification fails', () => {
            const ready =
                createReadyState();

            const switching =
                workspaceContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        target:
                            organizationWorkspace,
                    },
                );

            const restored =
                workspaceContextReducer(
                    switching,
                    {
                        type:
                            'SWITCH_FAILED',
                        failure:
                            networkFailure,
                    },
                );

            expect(restored).toEqual({
                status:
                    'ready',
                context,
                tenant:
                    discovery.tenant,
                workspaces:
                    discovery.workspaces,
                current:
                    tenantWorkspace,
                failure:
                    networkFailure,
            });
        });

        it('removes stale Workspace authority while recovery is active', () => {
            const ready =
                createReadyState();

            const switching =
                workspaceContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        target:
                            organizationWorkspace,
                    },
                );

            const organizationalReady =
                workspaceContextReducer(
                    switching,
                    {
                        type:
                            'TARGET_VERIFIED',
                    },
                );

            const recovering =
                workspaceContextReducer(
                    organizationalReady,
                    {
                        type:
                            'RECOVERY_STARTED',
                        failure:
                            contextDeniedFailure,
                    },
                );

            expect(recovering).toEqual({
                status:
                    'recovering',
                context,
                failure:
                    contextDeniedFailure,
            });

            expect(
                'current'
                    in recovering,
            ).toBe(false);

            expect(
                'workspaces'
                    in recovering,
            ).toBe(false);

            const rediscovering =
                workspaceContextReducer(
                    recovering,
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context,
                    },
                );

            expect(rediscovering).toEqual({
                status:
                    'discovering',
                context,
            });
        });
    },
);
