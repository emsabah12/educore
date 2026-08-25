import {
    waitFor,
} from '@testing-library/react';
import {
    http,
    HttpResponse,
} from 'msw';
import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import {
    createApplicationRuntime,
} from '@/app/runtime';
import type {
    WorkspaceSummary,
} from '@/platform/workspace';
import {
    apiMockServer,
} from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const userId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7eee-8def-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7fff-8def-1234567890ab';

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
            'EduCore Organization',
    };

afterEach(() => {
    window.sessionStorage.clear();
});

describe(
    'Application Capability to Workspace recovery',
    () => {
        it('recovers a stale organizational Capability projection to verified TENANT authority', async () => {
            let workspaceDiscoveryRequests =
                0;

            let tenantCapabilityRequests =
                0;

            let workspaceCapabilityRequests =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () =>
                        HttpResponse.json({
                            status:
                                'success',

                            data: {
                                user: {
                                    id:
                                        userId,

                                    email:
                                        'member@example.com',
                                },

                                person: {
                                    id:
                                        personId,

                                    name:
                                        'EduCore Member',
                                },

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
                                        'school',
                                },
                            },
                        }),
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () =>
                        HttpResponse.json({
                            status:
                                'success',

                            data: [
                                {
                                    membership_id:
                                        membershipId,

                                    membership_status:
                                        'ACTIVE',

                                    tenant_id:
                                        tenantId,

                                    tenant_name:
                                        'EduCore School',

                                    tenant_subdomain:
                                        'school',
                                },
                            ],
                        }),
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        workspaceDiscoveryRequests +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',

                            data: {
                                tenant: {
                                    id:
                                        tenantId,

                                    name:
                                        'EduCore School',
                                },

                                workspaces: [
                                    tenantWorkspace,
                                    organizationWorkspace,
                                ],
                            },
                        });
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        tenantCapabilityRequests +=
                            1;

                        return HttpResponse.json({
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

                                permissions:
                                    [],
                            },
                        });
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    () => {
                        workspaceCapabilityRequests +=
                            1;

                        /*
                         * First organizational projection is
                         * Workspace target verification.
                         *
                         * Once that target becomes committed,
                         * CapabilityRuntime issues its active
                         * projection. That second request is
                         * intentionally denied to simulate a
                         * stale organizational assignment.
                         */
                        if (
                            workspaceCapabilityRequests
                                === 1
                        ) {
                            return HttpResponse.json({
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

                                    permissions:
                                        [],
                                },
                            });
                        }

                        return HttpResponse.json(
                            {
                                status:
                                    'error',

                                code:
                                    'ORGANIZATIONAL_CONTEXT_DENIED',

                                message:
                                    'Organizational context is invalid or no longer available.',
                            },
                            {
                                status:
                                    403,
                            },
                        );
                    },
                ),
            );

            const runtime =
                createApplicationRuntime();

            try {
                await runtime.auth
                    .bootstrap();

                expect(
                    runtime.auth
                        .getState()
                        .status,
                ).toBe(
                    'authenticated',
                );

                await runtime.membership
                    .bootstrap();

                expect(
                    runtime.membership
                        .getState()
                        .status,
                ).toBe(
                    'ready',
                );

                await runtime.workspace
                    .bootstrap({
                        restoreHint:
                            false,
                    });

                await waitFor(() => {
                    expect(
                        runtime.workspace
                            .getState()
                            .status,
                    ).toBe(
                        'ready',
                    );

                    expect(
                        runtime.capabilities
                            .getState()
                            .status,
                    ).toBe(
                        'ready',
                    );
                });

                const initialWorkspaceState =
                    runtime.workspace
                        .getState();

                if (
                    initialWorkspaceState.status
                        !== 'ready'
                ) {
                    throw new Error(
                        'Expected initial verified TENANT Workspace.',
                    );
                }

                expect(
                    initialWorkspaceState.current,
                ).toEqual(
                    tenantWorkspace,
                );

                const switchedState =
                    await runtime.workspace
                        .switchWorkspace(
                            organizationWorkspace,
                        );

                /*
                 * switchWorkspace() proves that the target
                 * itself passed Workspace verification.
                 * Stale denial happens afterwards, during
                 * active Capability projection.
                 */
                expect(
                    switchedState.status,
                ).toBe(
                    'ready',
                );

                if (
                    switchedState.status
                        !== 'ready'
                ) {
                    throw new Error(
                        'Expected verified organizational Workspace before active Capability projection.',
                    );
                }

                expect(
                    switchedState.current,
                ).toEqual(
                    organizationWorkspace,
                );

                await waitFor(() => {
                    const workspaceState =
                        runtime.workspace
                            .getState();

                    expect(
                        workspaceState.status,
                    ).toBe(
                        'ready',
                    );

                    if (
                        workspaceState.status
                            !== 'ready'
                    ) {
                        return;
                    }

                    expect(
                        workspaceState.current,
                    ).toEqual(
                        tenantWorkspace,
                    );

                    const capabilityState =
                        runtime.capabilities
                            .getState();

                    expect(
                        capabilityState.status,
                    ).toBe(
                        'ready',
                    );

                    if (
                        capabilityState.status
                            !== 'ready'
                    ) {
                        return;
                    }

                    expect(
                        capabilityState
                            .projection
                            .scope,
                    ).toEqual({
                        type:
                            'tenant',

                        tenant_id:
                            tenantId,

                        membership_id:
                            membershipId,
                    });
                });

                /*
                 * Initial discovery + stale recovery
                 * discovery prove that Workspace recovery
                 * actually reran canonical discovery.
                 */
                expect(
                    workspaceDiscoveryRequests,
                ).toBeGreaterThanOrEqual(
                    2,
                );

                /*
                 * Exactly two organizational capability
                 * requests are expected:
                 *
                 * 1. target verification succeeds
                 * 2. active projection is denied
                 *
                 * Recovery itself deliberately falls back
                 * to TENANT and must not retry the stale
                 * organizational locator.
                 */
                expect(
                    workspaceCapabilityRequests,
                ).toBe(
                    2,
                );

                /*
                 * TENANT capability projection occurs
                 * during initial Workspace verification
                 * and again during/after stale recovery.
                 * The exact count is deliberately not an
                 * optimization contract because Workspace
                 * verification and CapabilityRuntime may
                 * independently project authority.
                 */
                expect(
                    tenantCapabilityRequests,
                ).toBeGreaterThanOrEqual(
                    2,
                );
            } finally {
                runtime.capabilities
                    .dispose();

                runtime.workspace
                    .dispose();
            }
        });
    },
);
