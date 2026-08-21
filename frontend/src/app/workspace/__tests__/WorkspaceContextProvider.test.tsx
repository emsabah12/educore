import {
    act,
    render,
    waitFor,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';
import {
    MembershipContextProvider,
} from '@/app/membership/MembershipContextProvider';
import {
    WorkspaceContextProvider,
} from '@/app/workspace/WorkspaceContextProvider';
import type {
    BrowserAuthRuntime,
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CanonicalMembershipContext,
    MembershipContextRuntime,
    MembershipContextState,
    MembershipSummary,
} from '@/platform/membership';
import type {
    WorkspaceContextBootstrapOptions,
    WorkspaceContextRuntime,
    WorkspaceContextState,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const otherTenantId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const tenantSubdomain =
    'educore-school';

const otherTenantSubdomain =
    'educore-school-b';

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
                tenantSubdomain,
        },
    };

const otherContext:
    CanonicalMembershipContext = {
        membership: {
            id:
                otherMembershipId,

            status:
                'ACTIVE',
        },

        tenant: {
            id:
                otherTenantId,

            name:
                'EduCore School B',

            subdomain:
                otherTenantSubdomain,
        },
    };

const membershipSummary:
    MembershipSummary = {
        membership_id:
            membershipId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantId,

        tenant_name:
            'EduCore School',

        tenant_subdomain:
            tenantSubdomain,
    };

const otherMembershipSummary:
    MembershipSummary = {
        membership_id:
            otherMembershipId,

        membership_status:
            'ACTIVE',

        tenant_id:
            otherTenantId,

        tenant_name:
            'EduCore School B',

        tenant_subdomain:
            otherTenantSubdomain,
    };

function createAuthenticatedState(
    canonicalContext:
        CanonicalMembershipContext,
): BrowserAuthState {
    return {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    '018f3b6a-7c20-7eee-8def-1234567890ab',

                email:
                    'member@example.com',
            },

            person: {
                id:
                    '018f3b6a-7c20-7fff-8def-1234567890ab',

                name:
                    'EduCore Member',
            },

            membership:
                canonicalContext
                    .membership,

            tenant:
                canonicalContext
                    .tenant,
        },
    };
}

function createReadyMembershipState(
    canonicalContext:
        CanonicalMembershipContext,
    memberships:
        readonly MembershipSummary[],
): MembershipContextState {
    return {
        status:
            'ready',

        memberships,

        context:
            canonicalContext,

        failure:
            null,
    };
}

interface ControlledAuth {
    readonly runtime:
        BrowserAuthRuntime;

    publish(
        state:
            BrowserAuthState,
    ): void;
}

function createControlledAuth(
    initialState:
        BrowserAuthState,
): ControlledAuth {
    let state =
        initialState;

    const listeners =
        new Set<
            (
                state:
                    BrowserAuthState,
            ) => void
        >();

    const runtime:
        BrowserAuthRuntime = {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        async bootstrap() {
            return state;
        },

        async login() {
            return state;
        },

        async logout() {
            return state;
        },

        observeFailure() {
            return state;
        },
    };

    return {
        runtime,

        publish(
            nextState,
        ) {
            state =
                nextState;

            for (
                const listener
                of listeners
            ) {
                listener(
                    state,
                );
            }
        },
    };
}

interface ControlledMembership {
    readonly runtime:
        MembershipContextRuntime;

    publish(
        state:
            MembershipContextState,
    ): void;
}

function createControlledMembership(
    initialState:
        MembershipContextState,
): ControlledMembership {
    let state =
        initialState;

    const listeners =
        new Set<
            () => void
        >();

    const publish =
        (
            nextState:
                MembershipContextState,
        ) => {
            state =
                nextState;

            for (
                const listener
                of listeners
            ) {
                listener();
            }
        };

    const runtime:
        MembershipContextRuntime = {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        async bootstrap() {
            return state;
        },

        async switchMembership() {
            return state;
        },

        reset() {
            publish({
                status:
                    'unresolved',
            });

            return state;
        },
    };

    return {
        runtime,
        publish,
    };
}

interface RecordingWorkspace {
    readonly runtime:
        WorkspaceContextRuntime;

    readonly bootstrapCalls:
        WorkspaceContextBootstrapOptions[];
}

function createRecordingWorkspace():
    RecordingWorkspace {
    let state:
        WorkspaceContextState = {
            status:
                'unresolved',
        };

    const listeners =
        new Set<
            () => void
        >();

    const bootstrapCalls:
        WorkspaceContextBootstrapOptions[] =
            [];

    const notify =
        () => {
            for (
                const listener
                of listeners
            ) {
                listener();
            }
        };

    const runtime:
        WorkspaceContextRuntime = {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        async bootstrap(
            options = {},
        ) {
            bootstrapCalls.push(
                options,
            );

            return state;
        },

        async switchWorkspace() {
            return state;
        },

        async recoverStaleWorkspace() {
            return state;
        },

        reset() {
            state = {
                status:
                    'unresolved',
            };

            notify();

            return state;
        },

        dispose() {
            listeners.clear();
        },
    };

    return {
        runtime,
        bootstrapCalls,
    };
}

interface RuntimeHarness {
    readonly auth:
        ControlledAuth;

    readonly membership:
        ControlledMembership;

    readonly workspace:
        RecordingWorkspace;
}

function renderHarness(
    harness:
        RuntimeHarness,
) {
    return render(
        <BrowserAuthProvider
            runtime={
                harness.auth.runtime
            }
        >
            <MembershipContextProvider
                runtime={
                    harness
                        .membership
                        .runtime
                }
            >
                <WorkspaceContextProvider
                    runtime={
                        harness
                            .workspace
                            .runtime
                    }
                >
                    <div>
                        Workspace child
                    </div>
                </WorkspaceContextProvider>
            </MembershipContextProvider>
        </BrowserAuthProvider>,
    );
}

describe(
    'WorkspaceContextProvider',
    () => {
        it('allows restoration for initial canonical context resolved from page bootstrap', async () => {
            const harness:
                RuntimeHarness = {
                auth:
                    createControlledAuth({
                        status:
                            'unknown',
                    }),

                membership:
                    createControlledMembership({
                        status:
                            'unresolved',
                    }),

                workspace:
                    createRecordingWorkspace(),
            };

            renderHarness(
                harness,
            );

            act(() => {
                harness.auth.publish(
                    createAuthenticatedState(
                        context,
                    ),
                );
            });

            act(() => {
                harness.membership.publish(
                    createReadyMembershipState(
                        context,
                        [
                            membershipSummary,
                        ],
                    ),
                );
            });

            await waitFor(() => {
                expect(
                    harness
                        .workspace
                        .bootstrapCalls,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness
                    .workspace
                    .bootstrapCalls[0]
                    ?.restoreHint,
            ).toBe(
                true,
            );
        });

        it('disables restoration after fresh authentication lifecycle', async () => {
            const harness:
                RuntimeHarness = {
                auth:
                    createControlledAuth({
                        status:
                            'anonymous',

                        failure:
                            null,
                    }),

                membership:
                    createControlledMembership({
                        status:
                            'unresolved',
                    }),

                workspace:
                    createRecordingWorkspace(),
            };

            renderHarness(
                harness,
            );

            act(() => {
                harness.auth.publish({
                    status:
                        'authenticating',
                });
            });

            act(() => {
                harness.auth.publish({
                    status:
                        'resolving-context',

                    login: {
                        membership_id:
                            membershipId,

                        tenant_id:
                            tenantId,
                    },
                });
            });

            act(() => {
                harness.auth.publish(
                    createAuthenticatedState(
                        context,
                    ),
                );
            });

            act(() => {
                harness.membership.publish(
                    createReadyMembershipState(
                        context,
                        [
                            membershipSummary,
                        ],
                    ),
                );
            });

            await waitFor(() => {
                expect(
                    harness
                        .workspace
                        .bootstrapCalls,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness
                    .workspace
                    .bootstrapCalls[0]
                    ?.restoreHint,
            ).toBe(
                false,
            );
        });

        it('disables restoration after canonical Membership switching', async () => {
            const harness:
                RuntimeHarness = {
                auth:
                    createControlledAuth(
                        createAuthenticatedState(
                            context,
                        ),
                    ),

                membership:
                    createControlledMembership(
                        createReadyMembershipState(
                            context,
                            [
                                membershipSummary,
                                otherMembershipSummary,
                            ],
                        ),
                    ),

                workspace:
                    createRecordingWorkspace(),
            };

            renderHarness(
                harness,
            );

            await waitFor(() => {
                expect(
                    harness
                        .workspace
                        .bootstrapCalls,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness
                    .workspace
                    .bootstrapCalls[0]
                    ?.restoreHint,
            ).toBe(
                true,
            );

            harness
                .workspace
                .bootstrapCalls
                .splice(
                    0,
                );

            act(() => {
                harness.membership.publish({
                    status:
                        'switching',

                    memberships: [
                        membershipSummary,
                        otherMembershipSummary,
                    ],

                    context,

                    target:
                        otherMembershipSummary,
                });
            });

            act(() => {
                harness.membership.publish(
                    createReadyMembershipState(
                        otherContext,
                        [
                            membershipSummary,
                            otherMembershipSummary,
                        ],
                    ),
                );
            });

            await waitFor(() => {
                expect(
                    harness
                        .workspace
                        .bootstrapCalls,
                ).toHaveLength(
                    1,
                );
            });

            expect(
                harness
                    .workspace
                    .bootstrapCalls[0]
                    ?.restoreHint,
            ).toBe(
                false,
            );
        });
    },
);
