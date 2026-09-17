import {
    render,
    screen,
} from '@testing-library/react';
import {
    createMemoryRouter,
} from 'react-router';
import {
    RouterProvider,
} from 'react-router/dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    WorkspaceContextState,
} from '@/platform/workspace';

const userId =
    '018f3b6a-7c20-7000-8000-000000000001';

const personId =
    '018f3b6a-7c20-7000-8000-000000000002';

const membershipId =
    '018f3b6a-7c20-7000-8000-000000000003';

const tenantId =
    '018f3b6a-7c20-7000-8000-000000000004';

const mocks =
    vi.hoisted<{
        authentication:
            BrowserAuthState;

        workspace:
            WorkspaceContextState;
    }>(
        () => ({
            authentication: {
                status:
                    'unknown',
            },

            workspace: {
                status:
                    'unresolved',
            },
        }),
    );

vi.mock(
    '@/app/auth/BrowserAuthProvider',
    () => ({
        useBrowserAuthState:
            () =>
                mocks.authentication,
    }),
);

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState:
            () =>
                mocks.workspace,
    }),
);

vi.mock(
    '@/app/auth/LogoutButton',
    () => ({
        LogoutButton() {
            return (
                <button
                    type="button"
                >
                    Logout
                </button>
            );
        },
    }),
);

vi.mock(
    '@/app/membership/MembershipSwitcher',
    () => ({
        MembershipSwitcher() {
            return null;
        },
    }),
);

vi.mock(
    '@/app/navigation/ApplicationNavigation',
    () => ({
        ApplicationNavigation() {
            return (
                <nav
                    aria-label="Navigasi utama"
                >
                    <a href="/">
                        Beranda
                    </a>
                </nav>
            );
        },
    }),
);

import {
    AuthenticatedApplicationShell,
} from '@/app/shell/AuthenticatedApplicationShell';

function configureAuthoritativeContext(): void {
    mocks.authentication = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'member@example.test',
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
                    'educore-school',
            },
        },
    };

    mocks.workspace = {
        status:
            'ready',

        context: {
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
        },

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

        current: {
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

        failure:
            null,
    };
}

function renderShell() {
    const router =
        createMemoryRouter(
            [
                {
                    path:
                        '/',

                    element: (
                        <AuthenticatedApplicationShell />
                    ),

                    children: [
                        {
                            index:
                                true,

                            element: (
                                <div>
                                    Protected nested content
                                </div>
                            ),
                        },
                    ],
                },
            ],
            {
                initialEntries: [
                    '/',
                ],
            },
        );

    render(
        <RouterProvider
            router={router}
        />,
    );
}

describe(
    'AuthenticatedApplicationShell',
    () => {
        beforeEach(
            () => {
                mocks.authentication = {
                    status:
                        'unknown',
                };

                mocks.workspace = {
                    status:
                        'unresolved',
                };
            },
        );

        it('renders authoritative Person, Tenant, Workspace, logout, navigation, and nested protected content', () => {
            configureAuthoritativeContext();

            renderShell();

            expect(
                screen.getByText(
                    'EduCore',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'EduCore School',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'EduCore Member',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'member@example.test',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Workspace: EduCore School',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Logout',
                    },
                ),
            ).toBeInTheDocument();

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
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Beranda',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Protected nested content',
                ),
            ).toBeInTheDocument();
        });

        it('keeps active user and Workspace context present in the responsive shell instead of hiding it on narrow layouts', () => {
            configureAuthoritativeContext();

            renderShell();

            const context =
                screen.getByLabelText(
                    'Konteks pengguna aktif',
                );

            expect(
                context,
            ).toBeInTheDocument();

            expect(
                context,
            ).not.toHaveClass(
                'hidden',
            );

            /*
             * The header moved from a CSS Grid layout to a
             * flex layout as part of the sidebar redesign
             * (Step 2). The structural implementation detail
             * (grid column span) is gone, but the behaviour
             * this test protects — user/Workspace context
             * stays rendered, not hidden, exactly once — is
             * still what matters and is asserted below via
             * getAllByText(...).toHaveLength(1).
             */

            expect(
                screen.getAllByText(
                    'EduCore Member',
                ),
            ).toHaveLength(
                1,
            );

            expect(
                screen.getAllByText(
                    'Workspace: EduCore School',
                ),
            ).toHaveLength(
                1,
            );
        });

        it('places application navigation inside the persistent sidebar landmark', () => {
            configureAuthoritativeContext();

            renderShell();

            const navigation =
                screen.getByRole(
                    'navigation',
                    {
                        name:
                            'Navigasi utama',
                    },
                );

            const sidebar =
                navigation.closest(
                    'aside',
                );

            /*
             * The Step 2 sidebar redesign replaced the
             * horizontally-scrolling top nav with a single
             * persistent vertical sidebar landmark. The
             * invariant that matters now is that navigation
             * always lives inside that sidebar, not that it
             * scrolls horizontally.
             */
            expect(
                sidebar,
            ).not.toBeNull();
        });

        it('fails closed before authentication authority is authenticated', () => {
            configureAuthoritativeContext();

            mocks.authentication = {
                status:
                    'unknown',
            };

            renderShell();

            expect(
                screen.queryByText(
                    'EduCore',
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
                screen.queryByText(
                    'Protected nested content',
                ),
            ).not.toBeInTheDocument();
        });

        it('fails closed before Workspace authority is ready', () => {
            configureAuthoritativeContext();

            mocks.workspace = {
                status:
                    'unresolved',
            };

            renderShell();

            expect(
                screen.queryByText(
                    'EduCore',
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
                screen.queryByText(
                    'Protected nested content',
                ),
            ).not.toBeInTheDocument();
        });

        it('provides a keyboard skip link to the canonical main content landmark', () => {
            configureAuthoritativeContext();

            renderShell();

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
        });

        it('keeps the skip link before authenticated header controls in document order', () => {
            configureAuthoritativeContext();

            renderShell();

            const skipLink =
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Lewati ke konten utama',
                    },
                );

            const logout =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Logout',
                    },
                );

            expect(
                skipLink.compareDocumentPosition(
                    logout,
                )
                    & Node.DOCUMENT_POSITION_FOLLOWING,
            ).not.toBe(
                0,
            );
        });
    },
);
