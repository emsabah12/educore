import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
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
                /*
                * BrowserAuth bootstrap is asynchronous and may remain in
                * the intentional unknown/pending state for longer than
                * Testing Library's default findBy timeout on a loaded CI
                * or development machine.
                *
                * Synchronize against canonical runtime authority first,
                * then verify the routed public presentation.
                */
                await waitFor(
                    () => {
                        expect(
                            runtime.auth
                                .getState()
                                .status,
                        ).toBe(
                            'anonymous',
                        );
                    },
                    {
                        timeout:
                            5000,
                    },
                );

                await waitFor(
                    () => {
                        expect(
                            runtime.router
                                .state
                                .location
                                .pathname,
                        ).toBe(
                            '/login',
                        );
                    },
                    {
                        timeout:
                            5000,
                    },
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                        {
                            timeout:
                                5000,
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

                expect(
                    screen.queryByRole(
                        'navigation',
                        {
                            name:
                                'Navigasi utama',
                        },
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByLabelText(
                        'Konteks pengguna aktif',
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'link',
                        {
                            name:
                                'Lewati ke konten utama',
                        },
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'Keluar',
                        },
                    ),
                ).not.toBeInTheDocument();
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });

        it('redirects an authenticated direct login route into the verified protected shell and canonical navigation', async () => {
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
                /*
                * LoginRouteBoundary must redirect the already
                * authenticated browser into the canonical
                * protected application.
                *
                * Waiting for the actual page heading also proves
                * that protected routing eventually reached the
                * application Outlet.
                */
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

                /*
                * These assertions use the real
                * AuthenticatedApplicationShell.
                *
                * They prove that authoritative canonical identity
                * and Workspace state are projected into the
                * authenticated presentation tree.
                */
                const activeContext =
                    screen.getByLabelText(
                        'Konteks pengguna aktif',
                    );

                expect(
                    within(
                        activeContext,
                    ).getByText(
                        'EduCore Member',
                    ),
                ).toBeInTheDocument();

                expect(
                    within(
                        activeContext,
                    ).getByText(
                        'member@example.com',
                    ),
                ).toBeInTheDocument();

                expect(
                    within(
                        activeContext,
                    ).getByText(
                        'Workspace: EduCore School',
                    ),
                ).toBeInTheDocument();

                /*
                * Navigation is not mocked here.
                *
                * The visible Beranda item therefore proves:
                *
                * Provider snapshots
                *   → React navigation adapter
                *   → pure navigation projection
                *   → canonical route policy registry
                *   → protected route evaluator
                *   → ApplicationNavigation
                */
                const navigation =
                    screen.getByRole(
                        'navigation',
                        {
                            name:
                                'Navigasi utama',
                        },
                    );

                const homeLink =
                    within(
                        navigation,
                    ).getByRole(
                        'link',
                        {
                            name:
                                'Beranda',
                        },
                    );

                expect(
                    homeLink,
                ).toHaveAttribute(
                    'href',
                    '/',
                );

                expect(
                    homeLink,
                ).toHaveAttribute(
                    'aria-current',
                    'page',
                );

                expect(
                    within(
                        navigation,
                    ).getAllByRole(
                        'link',
                    ),
                ).toHaveLength(
                    1,
                );

                /*
                * The shell owns one canonical main landmark and
                * the page must be rendered through its Outlet.
                */
                const main =
                    screen.getByRole(
                        'main',
                    );

                expect(
                    main,
                ).toHaveAttribute(
                    'id',
                    'main-content',
                );

                expect(
                    main,
                ).toHaveAttribute(
                    'tabindex',
                    '-1',
                );

                expect(
                    within(
                        main,
                    ).getByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).toBeInTheDocument();

                /*
                * Skip navigation and logout are also real shell
                * controls, not test substitutes.
                */
                expect(
                    screen.getByRole(
                        'link',
                        {
                            name:
                                'Lewati ke konten utama',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '#main-content',
                );

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'Keluar',
                        },
                    ),
                ).toBeInTheDocument();

                /*
                * Existing transport/lifecycle contracts remain
                * unchanged by shell/navigation integration.
                */
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
                * Workspace verification and active Capability
                * synchronization may currently each obtain a
                * TENANT capability projection.
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

        it('tears down the protected shell and downstream authority after browser logout returns the application to public login', async () => {
            window.history.replaceState(
                null,
                '',
                '/',
            );

            let csrfRequestCount =
                0;

            let logoutRequestCount =
                0;

            let membershipRequestCount =
                0;

            let workspaceRequestCount =
                0;

            let capabilityRequestCount =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/browser/session/csrf`,
                    () => {
                        csrfRequestCount +=
                            1;

                        /*
                        * Match the BrowserSession request-forgery
                        * bootstrap contract.
                        *
                        * BrowserAuth bootstrap and explicit logout
                        * may both establish this boundary, so the
                        * test snapshots the count immediately
                        * before logout instead of assuming this is
                        * the first CSRF request.
                        */
                        document.cookie =
                            'XSRF-TOKEN=logout%20integration; Path=/';

                        return new HttpResponse(
                            null,
                            {
                                status:
                                    204,
                            },
                        );
                    },
                ),

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

                http.post(
                    `${window.location.origin}/api/v1/browser/auth/logout`,
                    () => {
                        logoutRequestCount +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',

                            message:
                                'Logout completed successfully.',
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
                /*
                * Establish the real protected application before
                * exercising logout.
                */
                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'Frontend Foundation',
                        },
                    ),
                ).toBeInTheDocument();

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
                    runtime.router
                        .state
                        .location
                        .pathname,
                ).toBe(
                    '/',
                );

                /*
                * Prove the controls belong to the real protected
                * shell before logout.
                */
                expect(
                    screen.getByRole(
                        'navigation',
                        {
                            name:
                                'Navigasi utama',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByLabelText(
                        'Konteks pengguna aktif',
                    ),
                ).toBeInTheDocument();

                const logoutButton =
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'Keluar',
                        },
                    );

                /*
                * Snapshot all transport counts after initial
                * protected bootstrap.
                *
                * Logout must invalidate downstream truth; it must
                * not rediscover Membership, Workspace, or
                * Capability authority on the way to public login.
                */
                const csrfRequestsBeforeLogout =
                    csrfRequestCount;

                const membershipRequestsBeforeLogout =
                    membershipRequestCount;

                const workspaceRequestsBeforeLogout =
                    workspaceRequestCount;

                const capabilityRequestsBeforeLogout =
                    capabilityRequestCount;

                fireEvent.click(
                    logoutButton,
                );

                /*
                * Successful canonical logout must eventually move
                * BrowserAuth to authoritative anonymous truth.
                */
                await waitFor(() => {
                    expect(
                        runtime.auth
                            .getState(),
                    ).toEqual({
                        status:
                            'anonymous',

                        failure:
                            null,
                    });
                });

                /*
                * Authentication loss invalidates all downstream
                * authority.
                *
                * No stale Tenant/Workspace/Capability projection
                * may survive logout.
                */
                await waitFor(() => {
                    expect(
                        runtime.membership
                            .getState(),
                    ).toEqual({
                        status:
                            'unresolved',
                    });
                });

                await waitFor(() => {
                    expect(
                        runtime.workspace
                            .getState(),
                    ).toEqual({
                        status:
                            'unresolved',
                    });
                });

                await waitFor(() => {
                    expect(
                        runtime.capabilities
                            .getState(),
                    ).toEqual({
                        status:
                            'unresolved',
                    });
                });

                /*
                * Protected routing owns the transition back to the
                * public login boundary.
                *
                * The current protected root is retained only as a
                * validated navigation convenience, never as
                * authentication authority.
                */
                await waitFor(() => {
                    expect(
                        runtime.router
                            .state
                            .location
                            .pathname,
                    ).toBe(
                        '/login',
                    );
                });

                expect(
                    new URLSearchParams(
                        runtime.router
                            .state
                            .location
                            .search,
                    ).get(
                        'returnTo',
                    ),
                ).toBe(
                    '/',
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'Masuk ke EduCore',
                        },
                    ),
                ).toBeInTheDocument();

                /*
                * The authenticated presentation tree must be gone.
                *
                * Hiding only the page while retaining the old
                * identity/navigation shell would leak stale
                * authenticated context.
                */
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
                        'navigation',
                        {
                            name:
                                'Navigasi utama',
                        },
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByLabelText(
                        'Konteks pengguna aktif',
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'link',
                        {
                            name:
                                'Lewati ke konten utama',
                        },
                    ),
                ).not.toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'Keluar',
                        },
                    ),
                ).not.toBeInTheDocument();

                /*
                * Explicit logout owns exactly one logout mutation
                * and one additional CSRF/session bootstrap.
                */
                expect(
                    logoutRequestCount,
                ).toBe(
                    1,
                );

                expect(
                    csrfRequestCount,
                ).toBe(
                    csrfRequestsBeforeLogout
                        + 1,
                );

                /*
                * Leaving protected authority is cleanup-only.
                *
                * It must never trigger another downstream
                * discovery/projection cycle.
                */
                expect(
                    membershipRequestCount,
                ).toBe(
                    membershipRequestsBeforeLogout,
                );

                expect(
                    workspaceRequestCount,
                ).toBe(
                    workspaceRequestsBeforeLogout,
                );

                expect(
                    capabilityRequestCount,
                ).toBe(
                    capabilityRequestsBeforeLogout,
                );
            } finally {
                rendered.unmount();

                runtime.dispose();
            }
        });
    },
);
