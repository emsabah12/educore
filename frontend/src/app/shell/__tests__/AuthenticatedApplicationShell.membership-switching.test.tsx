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
    WorkspaceContextState,
} from '@/platform/workspace';

const userId =
    '018f3b6a-7c20-7000-8000-000000000101';

const personId =
    '018f3b6a-7c20-7000-8000-000000000102';

const membershipAId =
    '018f3b6a-7c20-7000-8000-000000000103';

const membershipBId =
    '018f3b6a-7c20-7000-8000-000000000104';

const tenantAId =
    '018f3b6a-7c20-7000-8000-000000000105';

const tenantBId =
    '018f3b6a-7c20-7000-8000-000000000106';

type MembershipSwitch =
    MembershipContextRuntime[
        'switchMembership'
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

function configureMultipleMembershipContext(): void {
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
                    membershipAId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantAId,

                name:
                    'EduCore School A',

                subdomain:
                    'educore-school-a',
            },
        },
    };

    mocks.membership = {
        status:
            'ready',

        memberships: [
            {
                membership_id:
                    membershipAId,

                membership_status:
                    'ACTIVE',

                tenant_id:
                    tenantAId,

                tenant_name:
                    'EduCore School A',

                tenant_subdomain:
                    'educore-school-a',
            },

            {
                membership_id:
                    membershipBId,

                membership_status:
                    'ACTIVE',

                tenant_id:
                    tenantBId,

                tenant_name:
                    'EduCore School B',

                tenant_subdomain:
                    'educore-school-b',
            },
        ],

        context: {
            membership: {
                id:
                    membershipAId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantAId,

                name:
                    'EduCore School A',

                subdomain:
                    'educore-school-a',
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
                    membershipAId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantAId,

                name:
                    'EduCore School A',

                subdomain:
                    'educore-school-a',
            },
        },

        tenant: {
            id:
                tenantAId,

            name:
                'EduCore School A',
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
                    'EduCore School A',
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
                'EduCore School A',
        },

        failure:
            null,
    };

    mocks.switchMembership
        .mockReset();
}

function configureSwitchingToMembershipB(): void {
    configureMultipleMembershipContext();

    if (
        mocks.membership.status
            !== 'ready'
    ) {
        throw new Error(
            'Expected ready Membership fixture before switching.',
        );
    }

    const memberships =
        mocks.membership.memberships;

    const target =
        memberships.find(
            (membership) =>
                membership.membership_id
                    === membershipBId,
        );

    if (target === undefined) {
        throw new Error(
            'Expected Membership B switching target.',
        );
    }

    mocks.membership = {
        status:
            'switching',

        memberships,

        context:
            mocks.membership.context,

        target,
    };
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
    'AuthenticatedApplicationShell Membership switching',
    () => {
        it('exposes discovered Memberships through an accessible institution switcher when more than one Membership is available', () => {
            configureMultipleMembershipContext();

            renderShell();

            const switcher =
                screen.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            expect(
                switcher,
            ).toHaveValue(
                membershipAId,
            );

            expect(
                screen.getByRole(
                    'option',
                    {
                        name:
                            'EduCore School A',
                    },
                ),
            ).toHaveValue(
                membershipAId,
            );

            expect(
                screen.getByRole(
                    'option',
                    {
                        name:
                            'EduCore School B',
                    },
                ),
            ).toHaveValue(
                membershipBId,
            );
        });

        it('delegates a different discovered Membership selection to the canonical Membership runtime exactly once', () => {
            configureMultipleMembershipContext();

            renderShell();

            const switcher =
                screen.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            fireEvent.change(
                switcher,
                {
                    target: {
                        value:
                            membershipBId,
                    },
                },
            );

            expect(
                mocks.switchMembership,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                mocks.switchMembership,
            ).toHaveBeenCalledWith(
                membershipBId,
            );
        });

        it('keeps the last confirmed Membership authoritative and disables selection while another Membership is switching', () => {
            configureSwitchingToMembershipB();

            renderShell();

            const switcher =
                screen.getByRole(
                    'combobox',
                    {
                        name:
                            'Switch institution',
                    },
                );

            expect(
                switcher,
            ).toBeDisabled();

            expect(
                switcher,
            ).toHaveAttribute(
                'aria-busy',
                'true',
            );

            expect(
                switcher,
            ).toHaveValue(
                membershipAId,
            );

            expect(
                screen.getByRole(
                    'status',
                ),
            ).toHaveTextContent(
                'Switching institution...',
            );

            /*
             * Membership B remains an available option, but
             * must not be projected as current Tenant authority
             * before canonical authentication confirmation.
             */
            expect(
                screen.getByRole(
                    'option',
                    {
                        name:
                            'EduCore School B',
                    },
                ),
            ).toHaveValue(
                membershipBId,
            );

            expect(
                screen.queryByText(
                    'EduCore School B',
                    {
                        selector:
                            'p',
                    },
                ),
            ).not.toBeInTheDocument();
        });
    },
);
