import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
    BrowserApiResult,
} from '@/platform/api';
import type {
    BrowserAuthState,
} from '@/platform/auth';
import {
    createMembershipContextRuntime,
    persistBrowserMembershipRestorationHint,
    readBrowserMembershipRestorationHint,
    type CanonicalMembershipContext,
    type MembershipAuthenticationRuntime,
    type MembershipContextOperations,
    type MembershipListSuccess,
    type MembershipSummary,
} from '@/platform/membership';

const userId =
    '018f3b6a-7c20-7000-8000-000000000001';

const personId =
    '018f3b6a-7c20-7000-8000-000000000002';

const membershipAId =
    '018f3b6a-7c20-7000-8000-000000000003';

const membershipBId =
    '018f3b6a-7c20-7000-8000-000000000004';

const tenantAId =
    '018f3b6a-7c20-7000-8000-000000000005';

const tenantBId =
    '018f3b6a-7c20-7000-8000-000000000006';

const membershipA:
    MembershipSummary = {
        membership_id:
            membershipAId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantAId,

        tenant_name:
            'EduCore School A',

        tenant_subdomain:
            'school-a',
    };

const membershipB:
    MembershipSummary = {
        membership_id:
            membershipBId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantBId,

        tenant_name:
            'EduCore School B',

        tenant_subdomain:
            'school-b',
    };

const canonicalContextA:
    CanonicalMembershipContext = {
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
                'school-a',
        },
    };

const canonicalContextB:
    CanonicalMembershipContext = {
        membership: {
            id:
                membershipBId,

            status:
                'ACTIVE',
        },

        tenant: {
            id:
                tenantBId,

            name:
                'EduCore School B',

            subdomain:
                'school-b',
        },
    };

const authenticatedA:
    BrowserAuthState = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'browser@educore.test',
            },

            person: {
                id:
                    personId,

                name:
                    'EduCore Browser User',
            },

            membership:
                canonicalContextA.membership,

            tenant:
                canonicalContextA.tenant,
        },
    };

const membershipRequiredFailure:
    BrowserApiFailure = {
        ok: false,

        kind:
            'response',

        status:
            403,

        error: {
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',

            message:
                'Browser membership context is required.',
        },
    };

const membershipRequiredState:
    BrowserAuthState = {
        status:
            'membership-context-required',

        failure:
            membershipRequiredFailure,
    };

function discoveryResult(
    memberships:
        readonly MembershipSummary[],
): BrowserApiResult<
    MembershipListSuccess
> {
    return {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data: [
                ...memberships,
            ],
        },
    };
}

function createOperations(
    memberships:
        readonly MembershipSummary[],
    onSwitch?:
        () => void,
): MembershipContextOperations {
    return {
        async discover() {
            return discoveryResult(
                memberships,
            );
        },

        async switchMembership() {
            onSwitch?.();

            throw new Error(
                'Browser membership switch must not run during restoration.',
            );
        },
    };
}

function createAuthenticationHarness(
    initialState:
        BrowserAuthState,
    canonicalRestorationState:
        BrowserAuthState = authenticatedA,
) {
    let state =
        initialState;

    const bootstrapMembershipIds:
        Array<
            string | null
        > = [];

    const authentication:
        MembershipAuthenticationRuntime = {
            getState() {
                return state;
            },

            async bootstrap(
                options,
            ) {
                bootstrapMembershipIds.push(
                    options?.membershipId
                        ?? null,
                );

                if (
                    options?.membershipId
                        !== undefined
                ) {
                    state =
                        canonicalRestorationState;
                }

                return state;
            },

            observeFailure() {
                return state;
            },
        };

    return {
        authentication,
        bootstrapMembershipIds,
    };
}

afterEach(
    () => {
        window.sessionStorage.clear();
    },
);

describe(
    'Membership runtime restoration',
    () => {
        it(
            'persists canonical Membership context only after normal authenticated discovery validates it',
            async () => {
                const harness =
                    createAuthenticationHarness(
                        authenticatedA,
                    );

                const runtime =
                    createMembershipContextRuntime(
                        createOperations([
                            membershipA,
                        ]),
                        harness.authentication,
                    );

                const state =
                    await runtime.bootstrap();

                expect(
                    state.status,
                ).toBe(
                    'ready',
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toEqual({
                    membership_id:
                        membershipAId,

                    tenant_id:
                        tenantAId,
                });

                expect(
                    harness
                        .bootstrapMembershipIds,
                ).toEqual([]);
            },
        );

        it(
            'restores a discovered Membership only after canonical authentication confirms the stored locator',
            async () => {
                persistBrowserMembershipRestorationHint(
                    canonicalContextA,
                );

                let switchCalls =
                    0;

                const harness =
                    createAuthenticationHarness(
                        membershipRequiredState,
                    );

                const runtime =
                    createMembershipContextRuntime(
                        createOperations(
                            [
                                membershipA,
                                membershipB,
                            ],
                            () => {
                                switchCalls +=
                                    1;
                            },
                        ),
                        harness.authentication,
                    );

                const state =
                    await runtime.bootstrap({
                        restoreHint:
                            true,
                    });

                expect(
                    harness
                        .bootstrapMembershipIds,
                ).toEqual([
                    membershipAId,
                ]);

                expect(
                    switchCalls,
                ).toBe(
                    0,
                );

                expect(state).toEqual({
                    status:
                        'ready',

                    memberships: [
                        membershipA,
                        membershipB,
                    ],

                    context:
                        canonicalContextA,

                    failure:
                        null,
                });
            },
        );

        it(
            'discards a restoration hint that is not present in canonical Membership discovery',
            async () => {
                persistBrowserMembershipRestorationHint(
                    canonicalContextB,
                );

                const harness =
                    createAuthenticationHarness(
                        membershipRequiredState,
                    );

                const runtime =
                    createMembershipContextRuntime(
                        createOperations([
                            membershipA,
                        ]),
                        harness.authentication,
                    );

                const state =
                    await runtime.bootstrap({
                        restoreHint:
                            true,
                    });

                expect(
                    state.status,
                ).toBe(
                    'selection-required',
                );

                expect(
                    harness
                        .bootstrapMembershipIds,
                ).toEqual([]);

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();
            },
        );

        it(
            'rejects canonical confirmation that does not match the stored and discovered Membership target',
            async () => {
                persistBrowserMembershipRestorationHint(
                    canonicalContextA,
                );

                const mismatchedAuthenticatedState:
                    BrowserAuthState = {
                        status:
                            'authenticated',

                        identity: {
                            user: {
                                id:
                                    userId,

                                email:
                                    'browser@educore.test',
                            },

                            person: {
                                id:
                                    personId,

                                name:
                                    'EduCore Browser User',
                            },

                            membership:
                                canonicalContextB.membership,

                            tenant:
                                canonicalContextB.tenant,
                        },
                    };

                const harness =
                    createAuthenticationHarness(
                        membershipRequiredState,
                        mismatchedAuthenticatedState,
                    );

                const runtime =
                    createMembershipContextRuntime(
                        createOperations([
                            membershipA,
                            membershipB,
                        ]),
                        harness.authentication,
                    );

                const state =
                    await runtime.bootstrap({
                        restoreHint:
                            true,
                    });

                expect(
                    state.status,
                ).toBe(
                    'selection-required',
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();
            },
        );

        it(
            'clears the restoration hint when Membership authority is explicitly reset',
            async () => {
                const harness =
                    createAuthenticationHarness(
                        authenticatedA,
                    );

                const runtime =
                    createMembershipContextRuntime(
                        createOperations([
                            membershipA,
                        ]),
                        harness.authentication,
                    );

                await runtime.bootstrap();

                expect(
                    readBrowserMembershipRestorationHint(),
                ).not.toBeNull();

                runtime.reset();

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();
            },
        );
    },
);
