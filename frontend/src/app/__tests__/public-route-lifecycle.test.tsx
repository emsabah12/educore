import {
    render,
    screen,
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
    AppBootstrap,
} from '@/app/AppBootstrap';
import {
    createApplicationRuntime,
} from '@/app/runtime';
import {
    apiMockServer,
} from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

afterEach(() => {
    window.history.replaceState(
        null,
        '',
        '/',
    );

    window.sessionStorage.clear();
});

describe(
    'Public route runtime lifecycle',
    () => {
        it('does not initialize Workspace or Capability authority while login is the active route', async () => {
            window.history.replaceState(
                null,
                '',
                '/login',
            );

            let membershipRequestCount =
                0;

            let workspaceRequestCount =
                0;

            let capabilityRequestCount =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',

                                code:
                                    'BROWSER_SESSION_AUTHENTICATION_REQUIRED',

                                message:
                                    'Authenticated browser session is required.',
                            },
                            {
                                status:
                                    401,
                            },
                        ),
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        membershipRequestCount +=
                            1;

                        return HttpResponse.json({
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
                        });
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        workspaceRequestCount +=
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
                                    {
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
                                    },
                                ],
                            },
                        });
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        capabilityRequestCount +=
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
            );

            const runtime =
                createApplicationRuntime();

            const rendered =
                render(
                    <AppBootstrap
                        runtime={runtime}
                    />,
                );

            try {
                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        runtime.auth
                            .getState()
                            .status,
                    ).toBe(
                        'anonymous',
                    );
                });

                /*
                * Public login must not activate downstream Membership,
                * Workspace, or Capability lifecycle for an authoritative
                * anonymous browser session.
                */
                expect(
                    runtime.membership
                        .getState(),
                ).toEqual({
                    status:
                        'unresolved',
                });

                expect(
                    membershipRequestCount,
                ).toBe(
                    0,
                );

                expect(
                    workspaceRequestCount,
                ).toBe(
                    0,
                );

                expect(
                    capabilityRequestCount,
                ).toBe(
                    0,
                );

                expect(
                    runtime.workspace
                        .getState(),
                ).toEqual({
                    status:
                        'unresolved',
                });

                expect(
                    runtime.capabilities
                        .getState(),
                ).toEqual({
                    status:
                        'unresolved',
                });
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });
    },
);
