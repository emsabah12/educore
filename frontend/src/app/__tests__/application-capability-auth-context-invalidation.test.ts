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

afterEach(
    () => {
        window.sessionStorage
            .clear();
    },
);

describe(
    'Application Capability authentication context invalidation',
    () => {
        it('invalidates authenticated application truth when active Capability projection receives canonical authentication context denial', async () => {
            let tenantCapabilityRequests =
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
                    () =>
                        HttpResponse.json({
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
                                ],
                            },
                        }),
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        tenantCapabilityRequests +=
                            1;

                        /*
                         * The first projection verifies the
                         * TENANT Workspace target.
                         *
                         * Once Workspace becomes canonical,
                         * CapabilityRuntime performs its own
                         * active authority projection. That
                         * second request is denied by the
                         * server to invalidate application
                         * authentication context.
                         */
                        if (
                            tenantCapabilityRequests
                                === 1
                        ) {
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
                        }

                        return HttpResponse.json(
                            {
                                status:
                                    'error',

                                code:
                                    'AUTHENTICATION_CONTEXT_DENIED',

                                message:
                                    'Authentication context missing or invalid.',
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
                const authenticatedState =
                    await runtime.auth
                        .bootstrap();

                expect(
                    authenticatedState.status,
                ).toBe(
                    'authenticated',
                );

                const membershipState =
                    await runtime.membership
                        .bootstrap();

                expect(
                    membershipState.status,
                ).toBe(
                    'ready',
                );

                await runtime.workspace
                    .bootstrap({
                        restoreHint:
                            false,
                    });

                await waitFor(
                    () => {
                        expect(
                            tenantCapabilityRequests,
                        ).toBeGreaterThanOrEqual(
                            2,
                        );

                        const authState =
                            runtime.auth
                                .getState();

                        expect(
                            authState.status,
                        ).toBe(
                            'anonymous',
                        );

                        if (
                            authState.status
                                !== 'anonymous'
                        ) {
                            return;
                        }

                        expect(
                            authState.failure,
                        ).toEqual({
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
                                    'AUTHENTICATION_CONTEXT_DENIED',

                                message:
                                    'Authentication context missing or invalid.',
                            },
                        });
                    },
                );
            } finally {
                runtime.dispose();
            }
        });
    },
);
