import {
    fireEvent,
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
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    MembershipContextRuntime,
    MembershipContextState,
} from '@/platform/membership';
import type {
    WorkspaceContextRuntime,
    WorkspaceContextState,
} from '@/platform/workspace';

const userId =
    '018f3b6a-7c20-7000-8000-000000000301';

const personId =
    '018f3b6a-7c20-7000-8000-000000000302';

const membershipId =
    '018f3b6a-7c20-7000-8000-000000000303';

const tenantId =
    '018f3b6a-7c20-7000-8000-000000000304';

const organizationalAssignmentId =
    '018f3b6a-7c20-7000-8000-000000000305';

const organizationId =
    '018f3b6a-7c20-7000-8000-000000000306';

type MembershipSwitch =
    MembershipContextRuntime[
        'switchMembership'
    ];

type WorkspaceSwitch =
    WorkspaceContextRuntime[
        'switchWorkspace'
    ];

const mocks =
    vi.hoisted<{
        authentication:
            BrowserAuthState;

        membership:
            MembershipContextState;

        workspace:
            WorkspaceContextState;

        switchMembership:
            ReturnType<
                typeof vi.fn<
                    MembershipSwitch
                >
            >;

        switchWorkspace:
            ReturnType<
                typeof vi.fn<
                    WorkspaceSwitch
                >
            >;
    }>(
        () => ({
            authentication: {
                status:
                    'unknown',
            },

            membership: {
                status:
                    'unresolved',
            },

            workspace: {
                status:
                    'unresolved',
            },

            switchMembership:
                vi.fn<
                    MembershipSwitch
                >(),

            switchWorkspace:
                vi.fn<
                    WorkspaceSwitch
                >(),
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
    '@/app/membership/MembershipContextProvider',
    () => ({
        useMembershipContextState:
            () =>
                mocks.membership,

        useMembershipContextRuntime:
            () => ({
                switchMembership:
                    mocks.switchMembership,
            }),
    }),
);

vi.mock(
    '@/app/workspace/WorkspaceContextProvider',
    () => ({
        useWorkspaceContextState:
            () =>
                mocks.workspace,

        useWorkspaceContextRuntime:
            () => ({
                switchWorkspace:
                    mocks.switchWorkspace,
            }),
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

function configureWorkspaceCatalog(): void {
    mocks.authentication = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'workspace-user@example.test',
            },

            person: {
                id:
                    personId,

                name:
                    'Workspace User',
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
                    'EduCore Workspace Tenant',

                subdomain:
                    'workspace-tenant',
            },
        },
    };

    mocks.membership = {
        status:
            'ready',

        memberships: [
            {
                membership_id:
                    membershipId,

                membership_status:
                    'ACTIVE',

                tenant_id:
                    tenantId,

                tenant_name:
                    'EduCore Workspace Tenant',

                tenant_subdomain:
                    'workspace-tenant',
            },
        ],

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
                    'EduCore Workspace Tenant',

                subdomain:
                    'workspace-tenant',
            },
        },

        failure:
            null,
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
                    'EduCore Workspace Tenant',

                subdomain:
                    'workspace-tenant',
            },
        },

        tenant: {
            id:
                tenantId,

            name:
                'EduCore Workspace Tenant',
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
                    'EduCore Workspace Tenant',
            },

            {
                type:
                    'ORGANIZATION',

                organizational_assignment_id:
                    organizationalAssignmentId,

                organization_id:
                    organizationId,

                organization_unit_id:
                    null,

                label:
                    'EduCore Organization A',
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
                'EduCore Workspace Tenant',
        },

        failure:
            null,
    };

    mocks.switchMembership
        .mockReset();

    mocks.switchWorkspace
        .mockReset();
}

function renderShell(): void {
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
                                    Protected content
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
    'AuthenticatedApplicationShell Workspace switching',
    () => {
        it('exposes discovered Workspace contexts through an accessible Workspace switcher when more than one Workspace is available', () => {
            configureWorkspaceCatalog();

            renderShell();

            expect(
                screen.getByText(
                    'Workspace: EduCore Workspace Tenant',
                ),
            ).toBeInTheDocument();

            const switcher =
                screen.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch Workspace',
                    },
                );

            expect(
                switcher,
            ).toHaveValue(
                'TENANT',
            );

            expect(
                screen.getByRole(
                    'option',
                    {
                        name:
                            'EduCore Workspace Tenant',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByRole(
                    'option',
                    {
                        name:
                            'EduCore Organization A',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('delegates an organizational Workspace selection to the canonical Workspace runtime using the discovered catalog object', () => {
            configureWorkspaceCatalog();

            if (
                mocks.workspace.status
                    !== 'ready'
            ) {
                throw new Error(
                    'Expected ready Workspace fixture before selection.',
                );
            }

            const targetWorkspace =
                mocks.workspace.workspaces.find(
                    (workspace) =>
                        workspace.type
                            === 'ORGANIZATION'
                        && workspace
                            .organizational_assignment_id
                            === organizationalAssignmentId,
                );

            if (
                targetWorkspace
                    === undefined
            ) {
                throw new Error(
                    'Expected canonical Organization Workspace target.',
                );
            }

            renderShell();

            const switcher =
                screen.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch Workspace',
                    },
                );

            fireEvent.change(
                switcher,
                {
                    target: {
                        value:
                            `ORGANIZATION:${organizationalAssignmentId}`,
                    },
                },
            );

            expect(
                mocks.switchWorkspace,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                mocks.switchWorkspace
                    .mock
                    .calls[0]?.[0],
            ).toBe(
                targetWorkspace,
            );

            /*
             * Workspace selection is not Membership or
             * authentication selection.
             */
            expect(
                mocks.switchMembership,
            ).not.toHaveBeenCalled();

            /*
             * A browser selection alone does not manufacture
             * new Workspace authority.
             *
             * The visible shell remains projected from
             * workspace.current until WorkspaceContextRuntime
             * publishes verified canonical state.
             */
            expect(
                screen.getByText(
                    'Workspace: EduCore Workspace Tenant',
                ),
            ).toBeInTheDocument();
        });
    },
);
