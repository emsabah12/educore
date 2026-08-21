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

const userId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const personId =
    '018f3b6a-7c20-7eee-8abc-1234567890ab';

function membershipResponse() {
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
}

function workspaceResponse() {
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
}

function capabilityResponse() {
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

afterEach(() => {
    window.history.replaceState(
        null,
        '',
        '/',
    );

    window.sessionStorage.clear();
});

describe(
    'Direct application navigation integration',
    () => {
        it('redirects an anonymous direct protected route to login without activating downstream context lifecycle', async () => {
            window.history.replaceState(
                null,
                '',
                '/',
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

                        return membershipResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        workspaceRequestCount +=
                            1;

                        return workspaceResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        capabilityRequestCount +=
                            1;

                        return capabilityResponse();
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

                expect(
                    runtime.router
                        .state
                        .location
                        .pathname,
                ).toBe(
                    '/login',
                );

                const parameters =
                    new URLSearchParams(
                        runtime.router
                            .state
                            .location
                            .search,
                    );

                expect(
                    parameters.get(
                        'returnTo',
                    ),
                ).toBe(
                    '/',
                );

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
                    runtime.membership
                        .getState(),
                ).toEqual({
                    status:
                        'unresolved',
                });

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

                expect(
                    screen.queryByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).not.toBeInTheDocument();
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });

        it('redirects an authenticated direct login route into the verified protected application', async () => {
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
                    () => {
                        membershipRequestCount +=
                            1;

                        return membershipResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        workspaceRequestCount +=
                            1;

                        return workspaceResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        capabilityRequestCount +=
                            1;

                        return capabilityResponse();
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
                                'Frontend Foundation',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    runtime.router
                        .state
                        .location
                        .pathname,
                ).toBe(
                    '/',
                );

                expect(
                    screen.queryByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).not.toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        runtime.auth
                            .getState()
                            .status,
                    ).toBe(
                        'authenticated',
                    );
                });

                await waitFor(() => {
                    expect(
                        runtime.membership
                            .getState()
                            .status,
                    ).toBe(
                        'ready',
                    );
                });

                await waitFor(() => {
                    expect(
                        runtime.workspace
                            .getState()
                            .status,
                    ).toBe(
                        'ready',
                    );
                });

                await waitFor(() => {
                    expect(
                        runtime.capabilities
                            .getState()
                            .status,
                    ).toBe(
                        'ready',
                    );
                });

                expect(
                    membershipRequestCount,
                ).toBe(
                    1,
                );

                expect(
                    workspaceRequestCount,
                ).toBe(
                    1,
                );

                /*
                 * Workspace verification and the active
                 * CapabilityRuntime projection may each
                 * request the same TENANT capability
                 * projection at this foundation stage.
                 */
                expect(
                    capabilityRequestCount,
                ).toBeGreaterThanOrEqual(
                    1,
                );
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });

        it('moves Membership-context-required login navigation into controlled Membership selection without activating Workspace authority', async () => {
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
                                    'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',

                                message:
                                    'Browser membership context is required.',
                            },
                            {
                                status:
                                    403,
                            },
                        ),
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        membershipRequestCount +=
                            1;

                        return membershipResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        workspaceRequestCount +=
                            1;

                        return workspaceResponse();
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () => {
                        capabilityRequestCount +=
                            1;

                        return capabilityResponse();
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
                                'Pilih Membership',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    runtime.router
                        .state
                        .location
                        .pathname,
                ).toBe(
                    '/',
                );

                await waitFor(() => {
                    expect(
                        runtime.auth
                            .getState()
                            .status,
                    ).toBe(
                        'membership-context-required',
                    );
                });

                await waitFor(() => {
                    expect(
                        runtime.membership
                            .getState()
                            .status,
                    ).toBe(
                        'selection-required',
                    );
                });

                expect(
                    membershipRequestCount,
                ).toBe(
                    1,
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

                expect(
                    screen.queryByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).not.toBeInTheDocument();
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });
    },
);
